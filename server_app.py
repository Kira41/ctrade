from __future__ import annotations

import asyncio
import os
import time
from contextlib import asynccontextmanager
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

from fastapi import FastAPI, HTTPException
from playwright.async_api import async_playwright

try:
    import psutil  # type: ignore
except Exception:
    psutil = None  # fallback: monitoring disabled


TARGET_DEFAULT = "/opt/te-api/trading.html"

JS_EXTRACT = r"""
() => {
  const rows = Array.from(document.querySelectorAll('div.market-quotes-widget__row--symbol'));
  return rows.map(row => {
    const txt = (sel) => {
      const el = row.querySelector(sel);
      if (!el) return null;
      const s = (el.textContent || '').trim();
      return s === '' ? null : s;
    };

    const name = (() => {
      const a = row.querySelector('.market-quotes-widget__field--name-row-cell a');
      const s = a ? (a.textContent || '').trim() : null;
      return s && s !== '' ? s : null;
    })();

    const value = txt('.js-symbol-last');
    const change = txt('.js-symbol-change');

    const chgp = (() => {
      const el = row.querySelector('.js-symbol-change-pt');
      if (!el) return null;
      const s = (el.textContent || '').trim();
      return s === '' ? null : s;
    })();

    const open = txt('.js-symbol-open');
    const high = txt('.js-symbol-high');
    const low  = txt('.js-symbol-low');
    const prev = txt('.js-symbol-prev-close');

    if (!name && !value) return null;

    return {
      "Name": name,
      "Value": value,
      "Change": change,
      "Chg%": chgp,
      "Open": open,
      "High": high,
      "Low": low,
      "Prev": prev
    };
  }).filter(Boolean);
}
"""


def to_url(target: str) -> str:
    target = target.strip()
    if "://" in target:
        return target
    p = Path(target)
    if not p.exists():
        raise FileNotFoundError(target)
    return p.resolve().as_uri()


def dedup(rows: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    seen: set = set()
    out: List[Dict[str, Any]] = []
    for r in rows:
        key: Tuple[Any, ...] = (
            r.get("Name"), r.get("Value"), r.get("Change"), r.get("Chg%"),
            r.get("Open"), r.get("High"), r.get("Low"), r.get("Prev")
        )
        if key in seen:
            continue
        seen.add(key)
        out.append(r)
    return out


class AdaptiveLimiter:
    """
    Limiter بسيط للتحكم بعدد الطلبات "المسموح أن تنتظر" قبل استخراج البيانات.
    - إذا كان السيرفر مضغوط: نقلل الوقت المسموح للانتظار ونرفض الزائد بـ 429.
    """
    def __init__(self, max_inflight: int = 1):
        self._max = max(1, int(max_inflight))
        self._inflight = 0
        self._cond = asyncio.Condition()

    def set_max(self, new_max: int) -> None:
        self._max = max(1, int(new_max))
        # إيقاظ المنتظرين لإعادة التقييم
        async def _notify():
            async with self._cond:
                self._cond.notify_all()
        try:
            loop = asyncio.get_running_loop()
            loop.create_task(_notify())
        except RuntimeError:
            pass

    async def acquire(self) -> None:
        async with self._cond:
            while self._inflight >= self._max:
                await self._cond.wait()
            self._inflight += 1

    async def release(self) -> None:
        async with self._cond:
            self._inflight = max(0, self._inflight - 1)
            self._cond.notify_all()


@dataclass
class LoadStatus:
    cpu_percent: float = 0.0
    mem_percent: float = 0.0
    level: str = "normal"  # normal | warn | throttle
    updated_at: float = 0.0


class State:
    def __init__(self):
        self.pw = None
        self.browser = None
        self.context = None
        self.page = None
        self.lock = asyncio.Lock()
        self.target_url: Optional[str] = None

        # frame cache
        self.data_frame = None

        # adaptive backpressure
        self.load = LoadStatus()
        self.monitor_task: Optional[asyncio.Task] = None
        self.limiter = AdaptiveLimiter(max_inflight=1)

        # cache
        self.cache_payload: Optional[Dict[str, Any]] = None
        self.cache_ts: float = 0.0


state = State()

# thresholds (يمكنك تغييرها عبر ENV)
WARN_PCT = float(os.getenv("TE_WARN_PCT", "75"))
THROTTLE_PCT = float(os.getenv("TE_THROTTLE_PCT", "80"))
RECOVER_PCT = float(os.getenv("TE_RECOVER_PCT", "70"))  # hysteresis

MONITOR_INTERVAL_S = float(os.getenv("TE_MONITOR_INTERVAL", "1.0"))

CACHE_TTL_THROTTLE_S = float(os.getenv("TE_CACHE_TTL_THROTTLE", "2.0"))
CACHE_TTL_WARN_S = float(os.getenv("TE_CACHE_TTL_WARN", "1.0"))
CACHE_TTL_NORMAL_S = float(os.getenv("TE_CACHE_TTL_NORMAL", "0.0"))  # 0 = لا كاش في الوضع الطبيعي

# throttle behavior
EXTRA_DELAY_THROTTLE_S = float(os.getenv("TE_EXTRA_DELAY_THROTTLE", "0.35"))
EXTRA_DELAY_WARN_S = float(os.getenv("TE_EXTRA_DELAY_WARN", "0.10"))

# when overloaded, reject if cannot acquire quickly
ACQUIRE_TIMEOUT_THROTTLE_S = float(os.getenv("TE_ACQUIRE_TIMEOUT_THROTTLE", "0.75"))
ACQUIRE_TIMEOUT_WARN_S = float(os.getenv("TE_ACQUIRE_TIMEOUT_WARN", "1.50"))
ACQUIRE_TIMEOUT_NORMAL_S = float(os.getenv("TE_ACQUIRE_TIMEOUT_NORMAL", "3.00"))


async def find_data_frame(page):
    """
    يحاول إيجاد الـ frame الذي يحتوي على rows مرة واحدة ثم نعيد استعماله.
    """
    # إذا كان لدينا frame محفوظ، نتأكد أنه مازال صالح
    if state.data_frame is not None:
        try:
            el = await state.data_frame.query_selector("div.market-quotes-widget__row--symbol")
            if el:
                return state.data_frame
        except Exception:
            state.data_frame = None

    # scan all frames
    for fr in page.frames:
        try:
            el = await fr.query_selector("div.market-quotes-widget__row--symbol")
            if el:
                state.data_frame = fr
                return fr
        except Exception:
            continue

    return None


async def wait_until_data_ready(page, timeout_ms: int = 15000, poll_ms: int = 150) -> bool:
    deadline = asyncio.get_running_loop().time() + (timeout_ms / 1000.0)

    fr = await find_data_frame(page)
    while asyncio.get_running_loop().time() < deadline:
        try:
            if fr is None:
                fr = await find_data_frame(page)
            if fr is not None:
                el = await fr.query_selector("div.market-quotes-widget__row--symbol .js-symbol-last")
                if el:
                    txt = (await el.inner_text() or "").strip()
                    if txt:
                        return True
        except Exception:
            pass

        await asyncio.sleep(poll_ms / 1000.0)

    return False


def compute_level(cpu: float, mem: float, prev_level: str) -> str:
    peak = max(cpu, mem)

    # hysteresis: لا نخرج من throttle إلا بعد RECOVER_PCT
    if prev_level == "throttle":
        return "normal" if peak < RECOVER_PCT else "throttle"

    if peak >= THROTTLE_PCT:
        return "throttle"
    if peak >= WARN_PCT:
        return "warn"
    return "normal"


async def resource_monitor_loop():
    """
    حلقة مراقبة CPU/RAM وتحديث وضع السيرفر.
    """
    if psutil is None:
        # لا نستطيع المراقبة بدون psutil
        state.load.level = "normal"
        state.load.updated_at = time.time()
        return

    # "warm up" للـ cpu_percent
    try:
        psutil.cpu_percent(interval=None)
    except Exception:
        pass

    while True:
        try:
            cpu = float(psutil.cpu_percent(interval=0.1))
            mem = float(psutil.virtual_memory().percent)
            prev = state.load.level
            lvl = compute_level(cpu, mem, prev)

            state.load = LoadStatus(cpu_percent=cpu, mem_percent=mem, level=lvl, updated_at=time.time())

            # تنظيم الضغط: في throttle نجعل الحصول على الدور أصعب (أقصر timeout)،
            # ونزيد TTL للكاش، ونزيد الـ delays داخل quotes.
            # (الـ limiter max inflight يبقى 1 لأن الصفحة shared، لكن هذا يمنع queue غير محدود عبر timeout)
            # يمكن لاحقًا توسيعها لو عندك عدة صفحات/contexts.
        except Exception:
            # لا توقف السيرفر بسبب monitor
            pass

        await asyncio.sleep(max(0.2, MONITOR_INTERVAL_S))


def current_cache_ttl() -> float:
    lvl = state.load.level
    if lvl == "throttle":
        return CACHE_TTL_THROTTLE_S
    if lvl == "warn":
        return CACHE_TTL_WARN_S
    return CACHE_TTL_NORMAL_S


def current_extra_delay() -> float:
    lvl = state.load.level
    if lvl == "throttle":
        return EXTRA_DELAY_THROTTLE_S
    if lvl == "warn":
        return EXTRA_DELAY_WARN_S
    return 0.0


def current_acquire_timeout() -> float:
    lvl = state.load.level
    if lvl == "throttle":
        return ACQUIRE_TIMEOUT_THROTTLE_S
    if lvl == "warn":
        return ACQUIRE_TIMEOUT_WARN_S
    return ACQUIRE_TIMEOUT_NORMAL_S


def current_poll_ms(user_poll_ms: int) -> int:
    # في الضغط: نكبر poll_ms لتقليل CPU داخل حلقات الانتظار
    lvl = state.load.level
    if lvl == "throttle":
        return max(int(user_poll_ms), 400)
    if lvl == "warn":
        return max(int(user_poll_ms), 250)
    return int(user_poll_ms)


@asynccontextmanager
async def lifespan(app: FastAPI):
    state.pw = await async_playwright().start()
    state.browser = await state.pw.chromium.launch(headless=True)
    state.context = await state.browser.new_context()

    state.target_url = to_url(TARGET_DEFAULT)
    state.page = await state.context.new_page()
    await state.page.goto(state.target_url, wait_until="load", timeout=60000)

    # start resource monitor
    state.monitor_task = asyncio.create_task(resource_monitor_loop())

    yield

    # shutdown
    if state.monitor_task:
        state.monitor_task.cancel()
        try:
            await state.monitor_task
        except Exception:
            pass

    try:
        if state.browser:
            await state.browser.close()
    finally:
        if state.pw:
            await state.pw.stop()


app = FastAPI(lifespan=lifespan)


@app.get("/health")
async def health():
    return {
        "ok": True,
        "target_url": state.target_url,
        "load": {
            "cpu_percent": state.load.cpu_percent,
            "mem_percent": state.load.mem_percent,
            "level": state.load.level,
            "updated_at": state.load.updated_at,
            "psutil": psutil is not None,
        },
    }


@app.get("/quotes")
async def quotes(ready_timeout_ms: int = 15000, poll_ms: int = 150):
    if not state.page:
        raise HTTPException(500, "Browser not initialized")

    # 1) serve cache if valid (خصوصًا تحت الضغط)
    ttl = current_cache_ttl()
    now = time.time()
    if ttl > 0 and state.cache_payload is not None and (now - state.cache_ts) <= ttl:
        return state.cache_payload

    # 2) backpressure: لا تسمح بانتظار طويل تحت الضغط
    acquire_timeout = current_acquire_timeout()
    try:
        await asyncio.wait_for(state.limiter.acquire(), timeout=acquire_timeout)
    except asyncio.TimeoutError:
        # تحت الضغط نرفض بسرعة بدل ما نعمل Queue طويلة
        raise HTTPException(
            status_code=429,
            detail=f"Server is busy (level={state.load.level}). Retry soon.",
            headers={"Retry-After": "2"},
        )

    try:
        # 3) optional extra delay to slow traffic under load
        extra_delay = current_extra_delay()
        if extra_delay > 0:
            await asyncio.sleep(extra_delay)

        # 4) single page access
        async with state.lock:
            # في الضغط، نخفف polling
            eff_poll_ms = current_poll_ms(poll_ms)

            ok = await wait_until_data_ready(
                state.page,
                timeout_ms=int(ready_timeout_ms),
                poll_ms=int(eff_poll_ms),
            )
            if not ok:
                raise HTTPException(504, "Timed out waiting for iframe data")

            fr = await find_data_frame(state.page)

            rows: List[Dict[str, Any]] = []
            if fr is not None:
                try:
                    data = await fr.evaluate(JS_EXTRACT)
                    if data and isinstance(data, list):
                        rows.extend(data)
                except Exception:
                    pass
            else:
                # fallback: scan frames
                for fr2 in state.page.frames:
                    try:
                        data = await fr2.evaluate(JS_EXTRACT)
                        if data and isinstance(data, list):
                            rows.extend(data)
                    except Exception:
                        pass

            payload = {"ok": True, "rows": dedup(rows), "level": state.load.level}

            # 5) update cache
            if ttl > 0:
                state.cache_payload = payload
                state.cache_ts = time.time()

            return payload
    finally:
        await state.limiter.release()
