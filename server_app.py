import re
import time
import math
import threading
from typing import Optional, Tuple

from fastapi import FastAPI, Header, HTTPException, Query
from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeoutError

app = FastAPI()

TE_API_KEY = "te_6XvQpK9jR2mN4sA7fH8uC1zL0wY3tG5eB9nD7kS2pV4qR8m"

PAIR_RE = re.compile(r"^[A-Za-z0-9._:-]+$")  # allow BRK.B etc.


# ---------------------------
# Anti-overload limiter (global, in-process)
# - Max 1 in-flight request
# - Min interval between accepted requests = 2s
# ---------------------------
class GlobalLimiter:
    def __init__(self, min_interval_sec: float = 2.0, max_in_flight: int = 1):
        self.min_interval = float(min_interval_sec)
        self._lock = threading.Lock()
        self._in_flight = 0
        self._max_in_flight = int(max_in_flight)
        self._last_accepted_mono = 0.0

    def try_acquire(self) -> Tuple[bool, float, str]:
        """
        Returns: (ok, retry_after_seconds, reason)
        """
        now = time.monotonic()
        with self._lock:
            # 1) Concurrency guard
            if self._in_flight >= self._max_in_flight:
                return False, 1.0, "busy"

            # 2) Min-interval guard
            wait = (self._last_accepted_mono + self.min_interval) - now
            if wait > 0:
                return False, wait, "rate_limited"

            # accept
            self._in_flight += 1
            self._last_accepted_mono = now
            return True, 0.0, "ok"

    def release(self) -> None:
        with self._lock:
            if self._in_flight > 0:
                self._in_flight -= 1


limiter = GlobalLimiter(min_interval_sec=2.0, max_in_flight=1)


def parse_pair(pair: str) -> Tuple[str, str]:
    pair = (pair or "").strip()
    if not pair or not PAIR_RE.match(pair):
        raise HTTPException(status_code=400, detail="Invalid pair format")
    if ":" not in pair:
        raise HTTPException(status_code=400, detail='pair must be like "EXCHANGE:SYMBOL"')
    ex, sym = pair.split(":", 1)
    ex = ex.strip().upper()
    sym = sym.strip().upper()
    if not ex or not sym:
        raise HTTPException(status_code=400, detail="Invalid EXCHANGE or SYMBOL")
    return ex, sym


def build_urls(exchange: str, symbol: str):
    u1 = f"https://www.tradingview.com/symbols/{symbol}/?exchange={exchange}"
    u2 = f"https://www.tradingview.com/symbols/{exchange}-{symbol}/"
    return [u1, u2]


@app.get("/tv/quote")
def tv_quote(
    currencyPair: Optional[str] = Query(default=None, description='Example: COINBASE:BTCUSD'),
    pair: Optional[str] = Query(default=None, description='Alias of currencyPair'),
    x_api_key: Optional[str] = Header(default=None, alias="X-API-Key"),
):
    # --- auth ---
    if x_api_key != TE_API_KEY:
        raise HTTPException(status_code=401, detail="Unauthorized")

    pair_val = currencyPair or pair
    if not pair_val:
        raise HTTPException(status_code=400, detail="Missing currencyPair (or pair)")

    # --- anti-overload ---
    ok, retry_after, reason = limiter.try_acquire()
    if not ok:
        # Retry-After header (useful for clients)
        retry_int = max(1, int(math.ceil(retry_after)))
        msg = "Server busy, try again shortly." if reason == "busy" else "Too many requests, slow down."
        raise HTTPException(
            status_code=429,
            detail=f"{msg} Retry after ~{retry_int}s",
            headers={"Retry-After": str(retry_int)},
        )

    started = time.time()
    browser = None
    context = None
    page = None

    try:
        exchange, symbol = parse_pair(pair_val)
        urls = build_urls(exchange, symbol)

        with sync_playwright() as p:
            browser = p.chromium.launch(
                headless=True,
                args=[
                    "--no-sandbox",
                    "--disable-setuid-sandbox",
                    "--disable-dev-shm-usage",
                ],
            )

            context = browser.new_context(
                user_agent=(
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                    "(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
                )
            )
            page = context.new_page()

            # Speed: block heavy resources
            def route_handler(route):
                rt = route.request.resource_type
                if rt in ("image", "font", "media"):
                    return route.abort()
                return route.continue_()

            page.route("**/*", route_handler)

            def extract_values(timeout_ms: int = 20_000):
                # last value
                last_el = page.wait_for_selector('[data-qa-id="symbol-last-value"]', timeout=timeout_ms)
                market_last = (last_el.inner_text() or "").strip() or None

                # change percent: first element only
                page.wait_for_selector(".js-symbol-change-pt", timeout=timeout_ms)
                els = page.query_selector_all(".js-symbol-change-pt")
                market_daily_Pchg = None
                if els:
                    market_daily_Pchg = (els[0].inner_text() or "").strip() or None

                return market_last, market_daily_Pchg

            last_err = None

            for url in urls:
                try:
                    page.goto(url, wait_until="domcontentloaded", timeout=60_000)
                    page.wait_for_timeout(800)  # small wait for TradingView dynamic render

                    market_last, market_daily_Pchg = extract_values()

                    missing = []
                    if market_last is None:
                        missing.append("market_last")
                    if market_daily_Pchg is None:
                        missing.append("market_daily_Pchg")

                    if market_last is None:
                        last_err = f"Required element [data-qa-id='symbol-last-value'] not found on {url}"
                        continue

                    return {
                        "ok": True,
                        "pair": f"{exchange}:{symbol}",
                        "exchange": exchange,
                        "symbol": symbol,
                        "url": url,
                        "market_last": market_last,
                        "market_daily_Pchg": market_daily_Pchg,
                        "missing": missing,
                        "took_ms": int((time.time() - started) * 1000),
                    }

                except PlaywrightTimeoutError as e:
                    last_err = f"Timeout on {url}: {type(e).__name__}: {str(e)}"
                except Exception as e:
                    last_err = f"Error on {url}: {type(e).__name__}: {str(e)}"

            return {
                "ok": False,
                "pair": f"{exchange}:{symbol}",
                "error": last_err or "Failed to extract values",
                "took_ms": int((time.time() - started) * 1000),
            }

    finally:
        # Close in correct order to free resources
        try:
            if page:
                page.close()
        except Exception:
            pass
        try:
            if context:
                context.close()
        except Exception:
            pass
        try:
            if browser:
                browser.close()
        except Exception:
            pass

        limiter.release()
