#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
WebWatch tikrinimo taškas (agentas).

Ši programa veikia jūsų kompiuteryje ir parsiunčia puslapius per šio kompiuterio
interneto ryšį, kai WebWatch to paprašo. Naudinga stebint savo svetaines iš kelių
vietų arba kai hostingo serveris negali pasiekti puslapio.

Paleidimas rankiniu būdu:   python3 ww-agent.py
Įdiegti (paleisti automatiškai):   python3 ww-agent.py --install
Pašalinti:                  python3 ww-agent.py --uninstall

Reikia tik Python 3 (jokių papildomų bibliotekų).
"""

import gzip
import json
import os
import re
import ssl
import sys
import time
import glob
import zlib
import signal
import shutil
import socket
import tempfile
import platform
import subprocess
import urllib.request
import urllib.error

SERVER = "__WW_SERVER__"          # pvz. https://watch.jusu-domenas.lt/
TOKEN = "__WW_TOKEN__"
NAME = "__WW_NAME__"
VERSION = "7"

POLL_WAIT = 25                    # kiek s serveris laiko atvirą „poll“
FETCH_TIMEOUT = 30                # greitam (be JS) parsiuntimui
BROWSER_WAIT_MS = 8000            # kiek laiko naršyklei leisti vykdyti JS
BROWSER_TIMEOUT = 35              # KIETAS naršyklės proceso limitas (s). Svarbu: agentas
                                  # privalo baigti greičiau nei serveris laukia (110 s), kitaip
                                  # užstringa ir blokuoja kitus darbus. Po timeout'o NEBANDOMA
                                  # antrą kartą – tai tik vėl užtruktų tiek pat.

ORPHAN_AGE = 60                   # agento naršyklės procesai, senesni nei tiek s – „našlaičiai"

# Bendri naršyklės parametrai. Svarbiausia – IŠJUNGTI ryšius su Google (atnaujinimai,
# safebrowsing, telemetrija): ribotame/lėtame tinkle jie „kabo" ir sukelia timeout'us.
CHROME_FLAGS = [
    "--headless=new", "--disable-gpu", "--no-first-run", "--no-default-browser-check",
    "--disable-extensions", "--mute-audio", "--no-sandbox", "--disable-dev-shm-usage",
    "--hide-scrollbars", "--disable-background-networking", "--disable-component-update",
    "--disable-default-apps", "--disable-sync", "--disable-translate", "--no-pings",
    "--metrics-recording-only", "--disable-crash-reporter", "--disable-breakpad",
    "--disable-renderer-backgrounding", "--disable-backgrounding-occluded-windows",
    "--disable-background-timer-throttling", "--disable-client-side-phishing-detection",
    "--disable-features=Translate,BackForwardCache,InterestCohort,OptimizationHints",
    "--password-store=basic", "--use-mock-keychain",
]
BROWSER_HEADERS = {
    "mobile": "Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 "
              "(KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1",
    "desktop": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
               "(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36",
}

# Blokavimo / patikros požymiai (kai reikia tikros naršyklės)
CHALLENGE_RE = re.compile(
    rb"cf-chl|challenge-platform|Just a moment|Attention Required|Checking your browser|"
    rb"_Incapsula_|px-captcha|captcha-delivery|datadome|ddos-guard|Pardon Our Interruption",
    re.I,
)


def find_browser():
    """Suranda įdiegtą Chrome / Edge / Chromium naršyklę (jos varikliui reikia tikro atspaudo)."""
    names = ["google-chrome", "google-chrome-stable", "chromium", "chromium-browser",
             "chrome", "microsoft-edge", "microsoft-edge-stable", "brave-browser"]
    for n in names:
        p = shutil.which(n)
        if p:
            return p
    guesses = []
    sysname = platform.system()
    if sysname == "Windows":
        pf = [os.environ.get("PROGRAMFILES", r"C:\Program Files"),
              os.environ.get("PROGRAMFILES(X86)", r"C:\Program Files (x86)"),
              os.environ.get("LOCALAPPDATA", "")]
        for base in pf:
            guesses += [
                os.path.join(base, r"Google\Chrome\Application\chrome.exe"),
                os.path.join(base, r"Microsoft\Edge\Application\msedge.exe"),
                os.path.join(base, r"BraveSoftware\Brave-Browser\Application\brave.exe"),
            ]
    elif sysname == "Darwin":
        guesses += [
            "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
            "/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge",
            "/Applications/Chromium.app/Contents/MacOS/Chromium",
            "/Applications/Brave Browser.app/Contents/MacOS/Brave Browser",
        ]
    for g in guesses:
        if g and os.path.exists(g):
            return g
    return None


BROWSER_PATH = None
BROWSER_CHECKED = False


def browser_path():
    global BROWSER_PATH, BROWSER_CHECKED
    if not BROWSER_CHECKED:
        BROWSER_PATH = find_browser()
        BROWSER_CHECKED = True
        if BROWSER_PATH:
            print("Rasta naršyklė sudėtingiems puslapiams: %s" % BROWSER_PATH)
        else:
            print("Naršyklė nerasta – sudėtingoms svetainėms įdiekite Chrome arba Edge.")
    return BROWSER_PATH


def _kill_tree(p):
    """Nužudo VISĄ naršyklės procesų medį (Chrome paleidžia daug vaikinių procesų –
    jei nužudytume tik tėvinį, vaikiniai liktų kaboti ir kauptųsi, kol kompiuteris
    nebepajėgtų paleisti naujos naršyklės – tada atrodo, kad „agentas išsijungė")."""
    try:
        if os.name == "nt":
            subprocess.run(["taskkill", "/F", "/T", "/PID", str(p.pid)],
                           capture_output=True)
        else:
            os.killpg(os.getpgid(p.pid), signal.SIGKILL)
    except Exception:  # noqa
        try:
            p.kill()
        except Exception:  # noqa
            pass


def _run_browser(args):
    """Paleidžia naršyklę atskirame procesų grupėje su KIETU laiko limitu.
    Grąžina (stdout, timed_out). Po timeout'o nužudomas visas medis."""
    kwargs = {"stdout": subprocess.PIPE, "stderr": subprocess.DEVNULL}
    if os.name == "nt":
        kwargs["creationflags"] = 0x00000200  # CREATE_NEW_PROCESS_GROUP
    else:
        kwargs["start_new_session"] = True     # sava procesų grupė -> killpg
    try:
        p = subprocess.Popen(args, **kwargs)
    except Exception as e:  # noqa
        raise
    try:
        out, _ = p.communicate(timeout=BROWSER_TIMEOUT)
        if os.name != "nt":
            # Pagrindinis procesas baigėsi – „iššluojam" grupę, jei liko vaikinių procesų
            try:
                os.killpg(p.pid, signal.SIGKILL)
            except Exception:  # noqa
                pass
        return out, False
    except subprocess.TimeoutExpired:
        _kill_tree(p)
        try:
            p.communicate(timeout=5)
        except Exception:  # noqa
            pass
        return b"", True


def cleanup_stale_profiles():
    """Pašalina senus laikinus naršyklės profilius, likusius po nutrauktų bandymų."""
    now = time.time()
    for pat in ("wwagent-*", "wwshot-*"):
        for d in glob.glob(os.path.join(tempfile.gettempdir(), pat)):
            try:
                if now - os.path.getmtime(d) > 300:  # senesni nei 5 min
                    shutil.rmtree(d, ignore_errors=True)
            except Exception:  # noqa
                pass


def browser_fetch(url, ua):
    """Parsiunčia puslapį per vietinę naršyklę (tikras TLS atspaudas + JavaScript)."""
    exe = browser_path()
    if not exe:
        return None
    profile = tempfile.mkdtemp(prefix="wwagent-")
    try:
        args = [exe] + CHROME_FLAGS + [
            "--blink-settings=imagesEnabled=false",  # tekstui paveikslėlių nereikia – greičiau
            "--user-data-dir=" + profile, "--user-agent=" + ua,
            "--virtual-time-budget=%d" % BROWSER_WAIT_MS, "--dump-dom", url,
        ]
        out, timed_out = _run_browser(args)
        if timed_out:
            return 0, b"", url, "", "naršyklė neatsakė per %ss (per lėtas/sunkus puslapis)" % BROWSER_TIMEOUT
        if not out or len(out) < 200:
            # tuščia, bet NE timeout – gal senesnė naršyklė nemoka „=new“; bandom seną režimą
            args[1] = "--headless"
            out2, timed_out = _run_browser(args)
            if not timed_out and out2:
                out = out2
        if out and not CHALLENGE_RE.search(out[:30000]):
            return 200, out, url, "text/html; charset=utf-8", ""
        return 403, out or b"", url, "text/html", "naršyklė negavo turinio (galimai reikia CAPTCHA)"
    except Exception as e:  # noqa
        return 0, b"", url, "", "naršyklės klaida: %s" % e
    finally:
        shutil.rmtree(profile, ignore_errors=True)


def browser_screenshot(url, ua):
    """Padaro puslapio ekrano nuotrauką (PNG) per vietinę naršyklę."""
    exe = browser_path()
    if not exe:
        return 0, b"", url, "", "naršyklė nerasta (ekrano nuotraukai reikia Chrome/Edge)"
    profile = tempfile.mkdtemp(prefix="wwshot-")
    out_png = os.path.join(profile, "shot.png")
    try:
        args = [exe] + CHROME_FLAGS + [
            "--force-device-scale-factor=1", "--window-size=1280,2000",
            "--user-data-dir=" + profile, "--user-agent=" + ua,
            "--virtual-time-budget=%d" % BROWSER_WAIT_MS,
            "--screenshot=" + out_png, url,
        ]
        _, timed_out = _run_browser(args)
        if timed_out:
            return 0, b"", url, "", "naršyklė neatsakė per %ss (per lėtas/sunkus puslapis)" % BROWSER_TIMEOUT
        if not os.path.exists(out_png) or os.path.getsize(out_png) < 100:
            args[1] = "--headless"  # senesnė naršyklė (ne po timeout'o)
            _, timed_out = _run_browser(args)
            if timed_out:
                return 0, b"", url, "", "naršyklė neatsakė per %ss" % BROWSER_TIMEOUT
        if os.path.exists(out_png) and os.path.getsize(out_png) >= 100:
            with open(out_png, "rb") as f:
                return 200, f.read(8 * 1024 * 1024), url, "image/png", ""
        return 0, b"", url, "", "nepavyko padaryti ekrano nuotraukos"
    except Exception as e:  # noqa
        return 0, b"", url, "", "naršyklės klaida: %s" % e
    finally:
        shutil.rmtree(profile, ignore_errors=True)


def api(action, params="", data=None, headers=None, timeout=POLL_WAIT + 15):
    url = "%sagent.php?action=%s%s" % (SERVER, action, params)
    req = urllib.request.Request(url, data=data, method="POST" if data is not None else "GET")
    req.add_header("X-Agent-Token", TOKEN)
    for k, v in (headers or {}).items():
        req.add_header(k, v)
    ctx = ssl.create_default_context()
    return urllib.request.urlopen(req, timeout=timeout, context=ctx)


def fetch_plain(url, ua, extra_headers):
    """Greitas parsiuntimas per Python (be JS). Grąžina (status, body, final_url, ctype, error)."""
    headers = {
        "User-Agent": ua,
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language": "lt-LT,lt;q=0.9,en;q=0.8",
        "Accept-Encoding": "gzip, deflate",
        "Connection": "close",
    }
    for k, v in (extra_headers or {}).items():
        if k:
            headers[k] = v
    try:
        req = urllib.request.Request(url, headers=headers)
        ctx = ssl.create_default_context()
        with urllib.request.urlopen(req, timeout=FETCH_TIMEOUT, context=ctx) as resp:
            raw = resp.read(8 * 1024 * 1024)
            enc = (resp.headers.get("Content-Encoding") or "").lower()
            if "gzip" in enc:
                raw = gzip.decompress(raw)
            elif "deflate" in enc:
                raw = zlib.decompress(raw, -zlib.MAX_WBITS)
            return resp.status, raw, resp.geturl(), resp.headers.get("Content-Type", ""), ""
    except urllib.error.HTTPError as e:
        body = b""
        try:
            body = e.read(8 * 1024 * 1024)
            if (e.headers.get("Content-Encoding") or "").lower().startswith("gzip"):
                body = gzip.decompress(body)
        except Exception:
            pass
        return e.code, body, url, e.headers.get("Content-Type", "") if e.headers else "", ""
    except (urllib.error.URLError, socket.timeout, ssl.SSLError, ConnectionError) as e:
        return 0, b"", url, "", str(getattr(e, "reason", e))
    except Exception as e:  # noqa
        return 0, b"", url, "", str(e)


def is_blocked(status, body):
    return status in (401, 403, 405, 406, 429, 451, 503) or bool(CHALLENGE_RE.search((body or b"")[:30000]))


def fetch(job):
    """Parsiunčia puslapį; prireikus – per vietinę naršyklę. Grąžina (status, body, final_url, ctype, error)."""
    ua = job.get("ua") or BROWSER_HEADERS["desktop"]
    url = job["url"]
    extra = job.get("headers") or {}
    want_browser = bool(job.get("browser"))

    # Ekrano nuotrauka (vaizdinis stebėjimas)
    if job.get("shot"):
        return browser_screenshot(url, ua)

    # JS puslapiams iškart per naršyklę; kitaip pirma greitas būdas
    if not want_browser:
        status, body, final_url, ctype, error = fetch_plain(url, ua, extra)
        if not is_blocked(status, body):
            return status, body, final_url, ctype, error
        print("  užblokuota (HTTP %s) – bandau per vietinę naršyklę…" % status)

    br = browser_fetch(url, ua)
    if br is not None:
        return br
    if want_browser:  # naršyklės nėra, bet reikėjo jos – pabandom paprastai
        return fetch_plain(url, ua, extra)
    return status, body, final_url, ctype, error  # naršyklės nėra – grąžinam blokuotą atsakymą


def send_result(job_id, status, body, final_url, ctype, error):
    import base64
    from urllib.parse import quote
    # Turinį suspaudžiam ir užkoduojam base64 – kad hostingo WAF (ModSecurity)
    # neatmestų POST su HTML/skriptų turiniu (dažna „neatsakė laiku“ priežastis).
    payload = base64.b64encode(gzip.compress(body or b""))
    headers = {
        "X-Status": str(status),
        "X-Final-Url": quote(final_url or "", safe=""),
        "X-Content-Type": (ctype or "")[:200],
        "X-Via": NAME[:20],
        "X-Error": quote((error or "")[:500], safe=""),
        "X-Body-Encoding": "gzip+base64",
        "Content-Type": "text/plain",
    }
    last = None
    for attempt in range(2):
        try:
            api("result", "&id=%d" % job_id, data=payload, headers=headers, timeout=40).read()
            return
        except Exception as e:  # noqa
            last = e
            time.sleep(2)
    raise last if last else RuntimeError("nepavyko grąžinti rezultato")


LOG_PATH = os.path.join(os.path.expanduser("~"), ".wwagent", "agent.log")
RECENT = []  # paskutinės žurnalo eilutės – siunčiamos serveriui (matomos WebWatch'e)


def log(msg):
    line = time.strftime("%Y-%m-%d %H:%M:%S ") + msg
    print(line, flush=True)
    RECENT.append(line)
    del RECENT[:-40]  # laikome tik paskutines 40
    try:
        os.makedirs(os.path.dirname(LOG_PATH), exist_ok=True)
        with open(LOG_PATH, "a", encoding="utf-8") as f:
            f.write(line + "\n")
        if os.path.getsize(LOG_PATH) > 1024 * 1024:  # laikome ~1 MB
            data = open(LOG_PATH, encoding="utf-8").read()[-400000:]
            open(LOG_PATH, "w", encoding="utf-8").write(data)
    except Exception:
        pass


def own_browser_pids():
    """PID'ai naršyklės procesų, kuriuos paleido ŠIS agentas – atpažįstami pagal jo
    laikino profilio žymę (wwagent-/wwshot-) komandinėje eilutėje. Jūsų pačių
    naršyklės langai NIEKADA neįtraukiami. None – nepavyko patikrinti."""
    # Tik SENESNI nei ORPHAN_AGE s: veikiančio darbo naršyklė visada nužudoma po BROWSER_TIMEOUT,
    # tad senesnis procesas garantuotai yra „našlaitis" (net jei kompiuteryje veiktų du agentai).
    try:
        if os.name == "nt":
            ps = ("Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -match 'ww(agent|shot)-' "
                  "-and $_.Name -notmatch '^(powershell|pwsh|python)' "
                  "-and $_.CreationDate -lt (Get-Date).AddSeconds(-%d) } | ForEach-Object { $_.ProcessId }" % ORPHAN_AGE)
            out = subprocess.run(["powershell", "-NoProfile", "-Command", ps],
                                 capture_output=True, timeout=25).stdout.decode("utf-8", "replace")
            return [int(x) for x in out.split() if x.strip().isdigit()]
        # „[w]w" gudrybė: pgrep neranda savęs paties (jo argumentas su skliaustais neatitinka)
        out = subprocess.run(["pgrep", "-f", "[w]w(agent|shot)-"],
                             capture_output=True, timeout=8).stdout.decode()
        pids = [int(x) for x in out.split() if x.strip().isdigit() and int(x) != os.getpid()]
        return [p for p in pids if _proc_age(p) > ORPHAN_AGE]
    except Exception:  # noqa
        return None


def _proc_age(pid):
    """Kiek sekundžių veikia procesas (Linux – /proc, Mac – ps). Nežinant – laikom senu."""
    try:
        if os.path.isdir("/proc/%d" % pid):
            return time.time() - os.stat("/proc/%d" % pid).st_ctime
        et = subprocess.run(["ps", "-o", "etime=", "-p", str(pid)],
                            capture_output=True, timeout=5).stdout.decode().strip()  # [[dd-]hh:]mm:ss
        days, _, rest = et.rpartition("-")
        secs = 0
        for part in rest.split(":"):
            secs = secs * 60 + int(part)
        return secs + (int(days) * 86400 if days else 0)
    except Exception:  # noqa
        return 10 ** 9


def clear_own_browsers():
    """Kviečiama TARP darbų: tuo metu agento naršyklė neturi veikti, tad visi rasti jos
    procesai – pakibę likučiai. Nužudom juos. Grąžina kiek rasta (-1 – nežinoma)."""
    pids = own_browser_pids()
    if pids is None:
        return -1
    for pid in pids:
        try:
            if os.name == "nt":
                subprocess.run(["taskkill", "/F", "/T", "/PID", str(pid)], capture_output=True, timeout=10)
            elif os.getpgid(pid) == pid:
                os.killpg(pid, signal.SIGKILL)  # grupės vadovas (mūsų paleista naršyklė) – visa grupė iškart
            else:
                os.kill(pid, signal.SIGKILL)
        except Exception:  # noqa
            pass
    if pids:
        log("Išvalyta pakibusių naršyklės procesų: %d" % len(pids))
    return len(pids)


def send_diag():
    """Nusiunčia serveriui paskutines žurnalo eilutes + naršyklės procesų skaičių.
    Taip viską matote tiesiog WebWatch'e, be jokio SSH ar failų kompiuteryje."""
    import base64
    try:
        procs = clear_own_browsers()
        head = "naršyklė: %s · pakibusių naršyklės procesų rasta ir išvalyta: %s" % (
            os.path.basename(BROWSER_PATH) if BROWSER_PATH else "nerasta",
            procs if procs >= 0 else "?")
        text = head + "\n" + "\n".join(RECENT[-30:])
        payload = base64.b64encode(gzip.compress(text.encode("utf-8")))
        api("diag", data=payload, headers={
            "X-Body-Encoding": "gzip+base64", "Content-Type": "text/plain",
            "X-Procs": str(procs),
        }, timeout=20).read()
    except Exception:  # noqa
        pass


def run():
    browser = platform.system() + " " + platform.release()
    log("WebWatch tikrinimo taškas „%s“ (v%s) paleistas. Serveris: %s" % (NAME, VERSION, SERVER))
    log("Žurnalas: %s" % LOG_PATH)
    browser_path()  # iš karto pranešam, ar rasta naršyklė
    cleanup_stale_profiles()
    idle_backoff = 2
    last_diag = 0
    send_diag()  # iškart pranešam būseną serveriui
    while True:
        try:
            # Periodiškai (kas ~60 s) nusiunčiam būseną serveriui, kad ją matytumėt WebWatch'e
            if time.time() - last_diag > 60:
                send_diag()
                cleanup_stale_profiles()
                last_diag = time.time()
            params = "&wait=%d&os=%s&v=%s&browser=%s" % (
                POLL_WAIT, platform.system().lower(), VERSION,
                urllib.parse.quote(browser[:40]))
            resp = api("poll", params)
            data = json.loads(resp.read().decode("utf-8", "replace"))
            job = data.get("job")
            if not job:
                idle_backoff = 2
                continue
            log("→ Tikrinu: %s%s" % (job["url"], " (ekrano nuotrauka)" if job.get("shot") else (" (naršyklė)" if job.get("browser") else "")))
            t0 = time.time()
            status, body, final_url, ctype, error = fetch(job)
            t1 = time.time()
            try:
                send_result(job["id"], status, body, final_url, ctype, error)
            except Exception as e:  # noqa
                log("  KLAIDA grąžinant serveriui po %.1fs (%d baitų): %s – gali blokuoti hostingo WAF arba per didelis failas" % (t1 - t0, len(body or b""), e))
                idle_backoff = 2
                continue
            t2 = time.time()
            log("  grąžinta serveriui: HTTP %s, %d baitų (parsiuntė %.1fs, išsiuntė %.1fs)%s"
                % (status, len(body or b""), t1 - t0, t2 - t1, ", klaida: " + error if error else ""))
            send_diag()  # po kiekvieno darbo – šviežia būsena serveryje
            last_diag = time.time()
            idle_backoff = 2
        except urllib.error.HTTPError as e:
            if e.code == 403:
                log("KLAIDA: serveris nebeatpažįsta šio kompiuterio (403) – raktas pakeistas arba taškas ištrintas.")
                log("       WebWatch nustatymuose prie šio taško spauskite „Įdiegti“ ir paleiskite komandą iš naujo.")
                time.sleep(60)
            else:
                log("Serverio klaida grąžinant (HTTP %s) – gali blokuoti hostingo apsauga (WAF). Bandau vėl po %ss" % (e.code, idle_backoff))
                time.sleep(idle_backoff)
                idle_backoff = min(idle_backoff * 2, 60)
        except KeyboardInterrupt:
            log("Sustabdyta.")
            return
        except Exception as e:  # noqa
            log("Nėra ryšio su serveriu (%s) – bandau vėl po %ss" % (e, idle_backoff))
            time.sleep(idle_backoff)
            idle_backoff = min(idle_backoff * 2, 60)


# ------------------------------------------------------------------ #
# Automatinis paleidimas (autostart)                                  #
# ------------------------------------------------------------------ #

def self_path():
    dst = os.path.join(os.path.expanduser("~"), ".wwagent", "ww-agent.py")
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    if os.path.abspath(sys.argv[0]) != dst:
        with open(dst, "w", encoding="utf-8") as f:
            f.write(open(os.path.abspath(sys.argv[0]), encoding="utf-8").read())
    return dst


def stop_old_agents():
    """Sustabdo kitus šiame kompiuteryje veikiančius agentus (senas versijas) – kitaip
    senas toliau „pasiima" darbus ir naujas atrodo neveikiantis."""
    if os.name == "nt":
        return
    try:
        out = subprocess.run(["pgrep", "-f", "[w]w-agent\\.py"], capture_output=True, timeout=8).stdout.decode()
        me = {os.getpid(), os.getppid()}
        n = 0
        for x in out.split():
            if x.isdigit() and int(x) not in me:
                try:
                    os.kill(int(x), signal.SIGKILL)
                    n += 1
                except Exception:  # noqa
                    pass
        if n:
            print("Sustabdyti seni agento procesai: %d" % n)
    except Exception:  # noqa
        pass


def install():
    stop_old_agents()
    dst = self_path()
    system = platform.system()
    py = sys.executable or "python3"
    if system == "Darwin":
        label = "com.webwatch.agent"
        plist = os.path.expanduser("~/Library/LaunchAgents/%s.plist" % label)
        os.makedirs(os.path.dirname(plist), exist_ok=True)
        with open(plist, "w") as f:
            f.write("""<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>%s</string>
  <key>ProgramArguments</key><array><string>%s</string><string>%s</string></array>
  <key>RunAtLoad</key><true/><key>KeepAlive</key><true/>
  <key>StandardErrorPath</key><string>%s/.wwagent/agent.log</string>
  <key>StandardOutPath</key><string>%s/.wwagent/agent.log</string>
</dict></plist>""" % (label, py, dst, os.path.expanduser("~"), os.path.expanduser("~")))
        os.system("launchctl unload '%s' 2>/dev/null; launchctl load '%s'" % (plist, plist))
        print("Įdiegta ir paleista fone (launchd). Veiks ir po perkrovimo. Šį langą galite uždaryti.")
        return  # launchd jau paleido – antro egzemplioriaus nebereikia
    elif system == "Linux":
        unit_dir = os.path.expanduser("~/.config/systemd/user")
        os.makedirs(unit_dir, exist_ok=True)
        unit = os.path.join(unit_dir, "wwagent.service")
        with open(unit, "w") as f:
            f.write("""[Unit]
Description=WebWatch tikrinimo taskas
After=network-online.target
[Service]
ExecStart=%s %s
Restart=always
RestartSec=10
[Install]
WantedBy=default.target
""" % (py, dst))
        if os.system("systemctl --user daemon-reload && systemctl --user enable wwagent.service"
                     " && systemctl --user restart wwagent.service") == 0:
            os.system("loginctl enable-linger $USER 2>/dev/null")
            print("Įdiegta ir paleista fone (systemd --user). Veiks ir po perkrovimo. Šį langą galite uždaryti.")
            return  # systemd jau paleido – antro egzemplioriaus nebereikia
        else:
            print("systemd nerastas. Pridėkite į autostart rankiniu būdu: %s %s" % (py, dst))
    else:
        # Windows (jei paleista per Python): registro Run raktas
        try:
            import winreg
            key = winreg.OpenKey(winreg.HKEY_CURRENT_USER,
                                 r"Software\Microsoft\Windows\CurrentVersion\Run", 0, winreg.KEY_SET_VALUE)
            winreg.SetValueEx(key, "WebWatchAgent", 0, winreg.REG_SZ,
                              '"%s" "%s"' % (py, dst))
            winreg.CloseKey(key)
            print("Įdiegta. Veiks po prisijungimo prie Windows. Paleidžiu dabar…")
        except Exception as e:  # noqa
            print("Nepavyko įdiegti automatiškai (%s). Paleiskite rankiniu būdu: %s %s" % (e, py, dst))
    print("Paleidžiu tikrinimo tašką…")
    run()


def uninstall():
    system = platform.system()
    stop_old_agents()
    if system == "Darwin":
        plist = os.path.expanduser("~/Library/LaunchAgents/com.webwatch.agent.plist")
        os.system("launchctl unload '%s' 2>/dev/null" % plist)
        if os.path.exists(plist):
            os.remove(plist)
    elif system == "Linux":
        os.system("systemctl --user disable --now wwagent.service 2>/dev/null")
    else:
        try:
            import winreg
            key = winreg.OpenKey(winreg.HKEY_CURRENT_USER,
                                 r"Software\Microsoft\Windows\CurrentVersion\Run", 0, winreg.KEY_SET_VALUE)
            winreg.DeleteValue(key, "WebWatchAgent")
            winreg.CloseKey(key)
        except Exception:
            pass
    print("Pašalinta.")


if __name__ == "__main__":
    import urllib.parse
    if "--install" in sys.argv:
        install()
    elif "--uninstall" in sys.argv:
        uninstall()
    else:
        run()
