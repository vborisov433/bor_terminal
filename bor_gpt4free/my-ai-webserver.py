import traceback
import sys
import os
import asyncio
import json
import time
import datetime
import logging
import random
from threading import Lock, Thread
from flask import Flask, request, render_template_string, jsonify

# ==================================================================================
# [SETUP] LOGGING SILENCER
# ==================================================================================
logging.getLogger("gemini_webapi").setLevel(logging.ERROR)
logging.getLogger("gemini_webapi.client").setLevel(logging.ERROR)
logging.getLogger("gemini_webapi.utils").setLevel(logging.ERROR)
logging.getLogger("urllib3").setLevel(logging.ERROR)
logging.getLogger("werkzeug").setLevel(logging.ERROR)

# ==================================================================================
# [SETUP] PATH CONFIGURATION
# ==================================================================================
current_dir = os.path.dirname(os.path.abspath(__file__))
gemini_src_path = os.path.join(current_dir, 'Gemini-API', 'src')

if os.path.exists(gemini_src_path):
    sys.path.append(gemini_src_path)
else:
    print(f"[WARNING] Missing folder: {gemini_src_path}")

try:
    from gemini_webapi import GeminiClient
    import gemini_webapi.utils
    # Prevent the lib from trying to load browser cookies automatically
    gemini_webapi.utils.load_browser_cookies = lambda: {}
except ImportError as e:
    print(f"[CRITICAL] Import failed: {e}")
    sys.exit(1)


# 1. The filename to read from
INPUT_FILENAME = "gemini.google.com_cookies.txt"
# 2. The filename to save to (for your main bot script)
OUTPUT_FILENAME = "gemini_cookies.json"
COOKIE_FILENAME = "gemini_cookies.json"
# (Optional) I will create the file with your data so this script runs immediately for you.
# If you already have the file locally, you can remove this block.
raw_cookie_data = ""

# Write the dummy file if it doesn't exist so the script below works
if not os.path.exists(INPUT_FILENAME):
    with open(INPUT_FILENAME, "w", encoding="utf-8") as f:
        f.write(raw_cookie_data)
    print(f"Created temporary file: {INPUT_FILENAME}")

def convert_netscape_to_json():
    print(f"Reading from {INPUT_FILENAME}...")

    cookies = {}
    try:
        with open(INPUT_FILENAME, 'r', encoding='utf-8') as f:
            for line in f:
                if line.startswith('#') or not line.strip():
                    continue
                parts = line.strip().split('\t')
                if len(parts) >= 7:
                    name = parts[5]
                    value = parts[6]
                    cookies[name] = value

        with open(OUTPUT_FILENAME, 'w') as f:
            json.dump(cookies, f, indent=2)

        print(f"✅ Success! Extracted {len(cookies)} cookies.")
        print(f"📂 Saved to: {OUTPUT_FILENAME}")

    except FileNotFoundError:
        print(f"❌ Error: File {INPUT_FILENAME} not found.")

convert_netscape_to_json()

# ==================================================================================
# [DATA] TEST PROMPTS (For Stress Testing)
# ==================================================================================
TEST_PROMPTS = [
    "Explain the theory of relativity in one sentence.",
    "What is the distance between Earth and Mars?",
    "Write a haiku about coding.",
    "Convert 100 Celsius to Fahrenheit.",
    "Who painted the Mona Lisa?",
    "What is the capital of Australia?",
    "Explain how a rainbow is formed.",
    "Name three types of clouds.",
    "What is the boiling point of nitrogen?",
    "Who wrote Hamlet?",
    "What is the square root of 144?",
    "Define 'recursion' in programming.",
    "What is the primary ingredient in hummus?",
    "How many bones are in the human body?",
    "What is the chemical symbol for Gold?",
    "Explain the concept of inflation.",
    "What year did the Titanic sink?",
    "Write a random inspirational quote."
]

# ==================================================================================
# [GLOBALS] SAFETY SWITCHES
# ==================================================================================
HOURLY_429_LOCKOUT = False

# ==================================================================================
# [CORE] PERSISTENT MANAGER
# ==================================================================================
class GeminiManager:
    def __init__(self):
        self.client = None
        self.chat = None
        self.chat_request_count = 0
        self.MAX_CHAT_TURNS = 30

        self.loop = asyncio.new_event_loop()
        self.async_lock = asyncio.Lock()

        self.cookie_files = [COOKIE_FILENAME]
        self.rate_limit_log = "rate_limit_events.log"
        self.invalid_response_log = "invalid_responses.log"
        self.debug_log = "general_debug.log"
        self.current_account_index = 0

        self.query_counter = 0
        self.log_lock = Lock()

        self.generation_timeout = 100
        self.total_timeout = 300
        self.is_rate_limited = False
        self.rate_limit_resume_time = 0

        # [NEW] Track consecutive content failures
        self.content_failure_count = 0

        self.thread = Thread(target=self._run_event_loop, daemon=True)
        self.thread.start()

    def _run_event_loop(self):
        asyncio.set_event_loop(self.loop)
        self.loop.run_forever()

    def _get_current_cookie_file(self):
        return self.cookie_files[self.current_account_index]

    def _rotate_account(self):
        if len(self.cookie_files) > 1:
            prev = self.current_account_index
            self.current_account_index = (self.current_account_index + 1) % len(self.cookie_files)
            print(f"[SYSTEM] 🔄 Rotating Account: {prev} -> {self.current_account_index}")
            self.chat = None
            self.chat_request_count = 0
            return True
        return False

    def _log_rate_limit(self, q_id):
        try:
            timestamp = time.strftime("%Y-%m-%d %H:%M:%S")
            msg = f"[{timestamp}] HIT 429 ERROR at Request #{q_id}\n"
            with open(self.rate_limit_log, "a") as f:
                f.write(msg)
        except Exception as e:
            print(f"[LOGGING ERROR] {e}")

    async def _ensure_client(self):
            if self.client: return
            async with self.async_lock:
                if self.client: return

                if self.is_rate_limited:
                    if time.time() < self.rate_limit_resume_time:
                        wait_time = int(self.rate_limit_resume_time - time.time())
                        raise Exception(f"System cooling down. Waiting {wait_time}s.")
                    else:
                        self.is_rate_limited = False

                target_cookie_file = self._get_current_cookie_file()
                if not os.path.exists(target_cookie_file):
                     print(f"[CRITICAL] Cookie file missing: {target_cookie_file}")
                     raise FileNotFoundError(f"Missing {target_cookie_file}")

                try:
                    with open(target_cookie_file, 'r') as f:
                        raw = json.load(f)

                    # Normalize to dict
                    cookies = {c['name']: c['value'] for c in raw if 'name' in c} if isinstance(raw, list) else raw

                    self.client = GeminiClient(
                        secure_1psid=cookies.get("__Secure-1PSID"),
                        secure_1psidts=cookies.get("__Secure-1PSIDTS")
                    )

                    user_agents = [
                        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
                        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36"
                    ]

                    if hasattr(self.client, "session"):
                        self.client.session.headers["User-Agent"] = random.choice(user_agents)
                        self.client.session.headers["Referer"] = "https://gemini.google.com/"
                        self.client.session.cookies.update(cookies)

                    for k, v in cookies.items():
                        self.client.cookies[k] = v

                    await self.client.init(timeout=40)
                    self.chat = None
                    self.chat_request_count = 0
                    print(f"[SYSTEM] Client Initialized with Full 6-Token Cookie Set")

                except Exception as e:
                    print(f"[INIT ERROR] {e}")
                    self.client = None
                    raise

    async def _execute_with_retry(self, prompt, q_id):
            global HOURLY_429_LOCKOUT

            if HOURLY_429_LOCKOUT:
                return "Error: System Locked (429/503/RepeatedFailure). Waiting for next hour."

            if self.is_rate_limited:
                remaining = self.rate_limit_resume_time - time.time()
                if remaining > 0:
                    return f"Error: System cooling down ({int(remaining)}s remaining)."
                else:
                    self.is_rate_limited = False

            # --- HUMAN-LIKE DELAY ---
            if len(prompt) < 150:
                typing_speed = len(prompt) * random.uniform(0.05, 0.1)
            else:
                typing_speed = random.uniform(1, 2.5)
            thinking_time = random.uniform(1, 3)
            await asyncio.sleep(typing_speed + thinking_time)
            # ------------------------

            attempts = 0
            max_attempts = 2

            while attempts < max_attempts:
                try:
                    await self._ensure_client()

                    if self.chat is None or self.chat_request_count >= self.MAX_CHAT_TURNS:
                        self.chat = self.client.start_chat()
                        self.chat_request_count = 0

                    self.chat_request_count += 1
                    active_chat = self.chat

                    response = await asyncio.wait_for(
                        active_chat.send_message(prompt),
                        timeout=self.generation_timeout
                    )

                    # [SUCCESS] Reset the failure count since we got a valid response
                    self.content_failure_count = 0
                    return response.text

                except asyncio.TimeoutError:
                    print(f"[WARN #{q_id}] Timeout")
                    attempts += 1

                except Exception as e:
                    error_str = str(e).lower()
                    print(f"[ERROR #{q_id}] {e}")

                    # [CASE 1] Rate Limit (429)
                    if "429" in error_str or "too many requests" in error_str:
                        print(f"[ALERT #{q_id}] 🛑 429 Rate Limit!")
                        self._log_rate_limit(q_id)
                        if self._rotate_account():
                            print(f"[SYSTEM #{q_id}] ♻️ Switched Account. Retrying...")
                            async with self.async_lock:
                                self.client = None
                                self.chat = None
                            attempts += 1
                            continue
                        else:
                            HOURLY_429_LOCKOUT = True
                            self.is_rate_limited = True
                            return "Error: Rate limit reached. Global lockout."

                    # [CASE 2] Session Rot (406, Invalid Response)
                    elif any(x in error_str for x in ["406", "invalid response"]):
                        print(f"\n{'='*20} [DEBUG] 406/INVALID RESPONSE - SKIPPING {'='*20}")

                        # [DEBUG] Print the failed prompt to console
                        print(f"FAILED PROMPT (REQ #{q_id}):\n{prompt}")
                        print(f"{'='*60}\n")

                        try:
                            timestamp = time.strftime("%Y-%m-%d %H:%M:%S")
                            with open(self.invalid_response_log, "a", encoding="utf-8") as f:
                                f.write(f"[{timestamp}] Req #{q_id} FAILED: {str(e)}\nPrompt: {prompt}\n{'-'*40}\n")
                        except: pass
                        return "Error: Skipped due to 406/Invalid Response."

                    # [CASE 3] Auth/Login issues (Only these trigger Re-init)
                    elif any(x in error_str for x in ["auth", "login", "session"]):
                        async with self.async_lock:
                            self.client = None
                            self.chat = None
                            self.chat_request_count = 0

                    # [CASE 4] Content Generation Failure (COUNT, LOG, & LOCKOUT)
                    elif "failed to generate contents" in error_str:
                        self.content_failure_count += 1

                        # 1. Print and Log
                        print(f"\n{'='*20} [DEBUG] FAILED TO GENERATE CONTENTS ({self.content_failure_count}/3) {'='*20}")
                        print(f"FAILED PROMPT:\n{prompt}")
                        print(f"{'='*60}\n")

                        try:
                            timestamp = time.strftime("%Y-%m-%d %H:%M:%S")
                            log_entry = (
                                f"[{timestamp}] Req #{q_id} FAILED TO GENERATE CONTENTS\n"
                                f"Count: {self.content_failure_count}/3\n"
                                f"Prompt: {prompt}\n"
                                f"{'-'*40}\n"
                            )
                            with open(self.debug_log, "a", encoding="utf-8") as f:
                                f.write(log_entry)
                        except Exception as log_err:
                            print(f"[LOG ERROR] Could not write to debug file: {log_err}")

                        # 2. Check Threshold
                        if self.content_failure_count >= 3:
                            print(f"\n[CRITICAL] 🛑 3 Consecutive Content Failures. Stopping requests for 1 hour.")
                            HOURLY_429_LOCKOUT = True
                            self.is_rate_limited = True
                            return "Error: System Locked due to repeated content generation failures."

                        return "Error: Failed to generate contents."

                    # [CASE 5] Server Unavailable (503) - Global Lockout
                    elif "503" in error_str:
                        print(f"\n{'='*20} [DEBUG] 503 SERVICE UNAVAILABLE - LOCKING OUT {'='*20}")
                        try:
                            timestamp = time.strftime("%Y-%m-%d %H:%M:%S")
                            log_entry = (
                                f"[{timestamp}] Request #{q_id} FAILED (503) -> GLOBAL LOCKOUT TRIGGERED\n"
                                f"Error: {str(e)}\n"
                                f"Prompt: {prompt}\n"
                                f"{'-'*40}\n"
                            )
                            with open(self.debug_log, "a", encoding="utf-8") as f:
                                f.write(log_entry)
                        except Exception as log_err:
                            print(f"[LOG ERROR] Could not write to debug file: {log_err}")

                        HOURLY_429_LOCKOUT = True
                        self.is_rate_limited = True
                        return "Error: 503 Service Unavailable. Global lockout."

                    # [CASE 6] Server Errors (500) - Retryable
                    elif "500" in error_str:
                            await asyncio.sleep(5)

                    attempts += 1
                    await asyncio.sleep(3)

            return "Error: Failed to generate response after retries."

    def query(self, prompt):
        with self.log_lock:
            self.query_counter += 1
            q_id = self.query_counter

        if self.is_rate_limited and time.time() < self.rate_limit_resume_time:
             return f"Error: System cooling down ({int(self.rate_limit_resume_time - time.time())}s remaining)."

        clean_prompt = prompt.strip()
        print(f"\n[QUERY #{q_id}] Processing...")

        future = asyncio.run_coroutine_threadsafe(
            self._execute_with_retry(clean_prompt, q_id),
            self.loop
        )

        try:
            result = future.result(timeout=self.total_timeout)
            return result
        except Exception as e:
            return f"Error: Request processing failed ({str(e)})"

# ==================================================================================
# [FLASK] APP SETUP
# ==================================================================================
app = Flask(__name__)
log = logging.getLogger('werkzeug')
log.setLevel(logging.ERROR)

bot_manager = GeminiManager()
STRESS_TEST_RUNNING = False

def run_stress_test_loop():
    global STRESS_TEST_RUNNING, HOURLY_429_LOCKOUT
    print("\n[TEST] 🧪 STARTED: Stress Testing with HUMAN-LIKE Timing...")

    while STRESS_TEST_RUNNING:
        if HOURLY_429_LOCKOUT:
            print("[TEST] 🛑 System reported Global Lockout! Test stopping.")
            break

        prompt = random.choice(TEST_PROMPTS)
        result = bot_manager.query(prompt)

        if "Rate limit reached" in result or "Lockout" in result:
            print("[TEST] 🛑 Received Lockout Error. Stopping.")
            break

        if result.startswith("Error"):
             print(f"[TEST] ⚠️  {result}")
        else:
            clean_res = result.replace('\n', ' ').replace('\r', '')
            short_res = (clean_res[:70] + '..') if len(clean_res) > 70 else clean_res
            print(f"[TEST] ✅ Answer: {short_res}")

        wait_time = random.uniform(10, 25)
        if random.random() < 0.15:
            extra = random.uniform(20, 60)
            print(f"[TEST] ☕ Taking a break... (+{extra:.0f}s)")
            wait_time += extra

        time.sleep(wait_time)

@app.route('/api/test-limit', methods=['POST'])
def api_test_limit():
    global STRESS_TEST_RUNNING
    if STRESS_TEST_RUNNING:
        return jsonify({"status": "error", "message": "Test already running"}), 400

    STRESS_TEST_RUNNING = True
    thread = Thread(target=run_stress_test_loop, daemon=True)
    thread.start()
    return jsonify({"status": "success", "message": "Stress test started."})

@app.route('/api/stop-test', methods=['POST'])
def api_stop_test():
    global STRESS_TEST_RUNNING
    STRESS_TEST_RUNNING = False
    return jsonify({"status": "success", "message": "Stopping test..."})

# ==================================================================================
# [FLASK] API ROUTE
# ==================================================================================
QUOTA_LOCK = Lock()
MAX_HOURLY_REQUESTS = 91
CURRENT_HOUR_TRACKER = -1
SESSION_COUNTER = 0
LAST_LOG_TIME = 0

@app.route('/api/ask-gpt', methods=['POST'])
def api_ask():
    global SESSION_COUNTER, CURRENT_HOUR_TRACKER, LAST_LOG_TIME, HOURLY_429_LOCKOUT

    with QUOTA_LOCK:
        now = datetime.datetime.now()
        this_hour = now.hour

        if this_hour != CURRENT_HOUR_TRACKER:
            print(f"[SYSTEM] 🕒 New Hour Detected ({this_hour}:00). Resetting Quotas & Lockouts.")
            CURRENT_HOUR_TRACKER = this_hour
            SESSION_COUNTER = 0
            HOURLY_429_LOCKOUT = False
            # [RESET] Also reset content failure count on new hour for safety
            bot_manager.content_failure_count = 0

        if HOURLY_429_LOCKOUT:
            next_hour = (now + datetime.timedelta(hours=1)).replace(minute=0, second=0, microsecond=0)
            minutes_left = int((next_hour - now).total_seconds() / 60) + 1
            if time.time() - LAST_LOG_TIME > 10:
                print(f"[API] ⛔ SYSTEM LOCKED. Paused until next hour (~{minutes_left} min left).")
                LAST_LOG_TIME = time.time()
            return jsonify({}), 200

        if SESSION_COUNTER >= MAX_HOURLY_REQUESTS:
            if time.time() - LAST_LOG_TIME > 10:
                print(f"[API] ⛔ HOURLY QUOTA REACHED ({SESSION_COUNTER}). Dropping requests.")
                LAST_LOG_TIME = time.time()
            return jsonify({}), 200

    try:
        data = request.get_json(silent=True) or {}
        prompt = data.get('prompt') or data.get('question')

        if not prompt:
            return jsonify({"error": "Missing prompt"}), 400

        result = bot_manager.query(prompt)

        if result and not result.startswith("Error"):
            with QUOTA_LOCK:
                SESSION_COUNTER += 1
                if SESSION_COUNTER % 5 == 0:
                    print(f"[API] 📊 Hourly Quota: {SESSION_COUNTER}/{MAX_HOURLY_REQUESTS}")

        return jsonify({"status": "success", "answer": result})

    except Exception as e:
        return jsonify({"status": "error", "message": str(e)}), 500

@app.route('/', methods=['GET', 'POST'])
def web_index():
    answer, error, question = "", "", ""
    if request.method == 'POST':
        question = request.form.get('question', '')
        answer = bot_manager.query(question)
        if answer.startswith("Error"):
            error, answer = answer, ""
    return render_template_string(HTML_TEMPLATE, answer=answer, question=question, error=error)

HTML_TEMPLATE = """
<!DOCTYPE html>
<html>
<head><title>Gemini API</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-5 bg-light">
    <div class="container" style="max-width: 800px;">
        <h2 class="mb-4">🤖 Gemini API</h2>
        <div class="card p-3 mb-4">
            <h5>🧪 Stress Test Control</h5>
            <div class="d-flex gap-2">
                <button onclick="fetch('/api/test-limit', {method:'POST'}).then(r=>r.json()).then(d=>alert(d.message))" class="btn btn-warning">Start Test</button>
                <button onclick="fetch('/api/stop-test', {method:'POST'}).then(r=>r.json()).then(d=>alert(d.message))" class="btn btn-danger">Stop Test</button>
            </div>
        </div>
        <form method="post">
            <textarea name="question" class="form-control mb-3" rows="5" placeholder="Enter prompt...">{{question}}</textarea>
            <button class="btn btn-primary w-100">Submit</button>
        </form>
        {% if answer %}<div class="card mt-4 shadow-sm"><div class="card-body"><pre style="white-space: pre-wrap;">{{answer}}</pre></div></div>{% endif %}
        {% if error %}<div class="alert alert-danger mt-4">{{error}}</div>{% endif %}
    </div>
</body>
</html>
"""

if __name__ == '__main__':
    print("[SYS] Server starting at http://localhost:5000")
    app.run(host='0.0.0.0', port=5000, debug=False)