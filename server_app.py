from __future__ import annotations

import asyncio
from contextlib import asynccontextmanager
from pathlib import Path
from typing import Any, Dict, List, Tuple

from fastapi import FastAPI, HTTPException
from playwright.async_api import async_playwright


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

async def wait_until_data_ready(page, timeout_ms: int = 15000, poll_ms: int = 150) -> bool:
    deadline = asyncio.get_running_loop().time() + (timeout_ms / 1000.0)
    while asyncio.get_running_loop().time() < deadline:
        for fr in page.frames:
            try:
                el = await fr.query_selector("div.market-quotes-widget__row--symbol .js-symbol-last")
                if not el:
                    continue
                txt = (await el.inner_text() or "").strip()
                if txt:
                    return True
            except Exception:
                pass
        await asyncio.sleep(poll_ms / 1000.0)
    return False

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

class State:
    def __init__(self):
        self.pw = None
        self.browser = None
        self.context = None
        self.page = None
        self.lock = asyncio.Lock()
        self.target_url = None

state = State()

@asynccontextmanager
async def lifespan(app: FastAPI):
    state.pw = await async_playwright().start()
    state.browser = await state.pw.chromium.launch(headless=True)
    state.context = await state.browser.new_context()

    state.target_url = to_url(TARGET_DEFAULT)
    state.page = await state.context.new_page()
    await state.page.goto(state.target_url, wait_until="load", timeout=60000)

    yield

    try:
        if state.browser:
            await state.browser.close()
    finally:
        if state.pw:
            await state.pw.stop()

app = FastAPI(lifespan=lifespan)

@app.get("/health")
async def health():
    return {"ok": True, "target_url": state.target_url}

@app.get("/quotes")
async def quotes(ready_timeout_ms: int = 15000, poll_ms: int = 150):
    async with state.lock:
        if not state.page:
            raise HTTPException(500, "Browser not initialized")

        ok = await wait_until_data_ready(state.page, timeout_ms=ready_timeout_ms, poll_ms=poll_ms)
        if not ok:
            raise HTTPException(504, "Timed out waiting for iframe data")

        rows: List[Dict[str, Any]] = []
        for fr in state.page.frames:
            try:
                data = await fr.evaluate(JS_EXTRACT)
                if data and isinstance(data, list):
                    rows.extend(data)
            except Exception:
                pass

        return {"ok": True, "rows": dedup(rows)}
