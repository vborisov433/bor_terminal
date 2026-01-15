import traceback
import sys
import os
import asyncio
import json
import time
import logging
import random
from threading import Lock, Thread
from flask import Flask, request, render_template_string, jsonify

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

# ==================================================================================
# [DATA] TEST PROMPTS (For Stress Testing)
# ==================================================================================
TEST_PROMPTS = [
    "Explain the theory of relativity in one sentence.",
    "What is the distance between Earth and Mars?",
    "Write a haiku about coding.",
    "What are the three laws of robotics?",
    "Convert 100 Celsius to Fahrenheit.",
    "Who painted the Mona Lisa?",
    "What is the capital of Australia?",
    "Explain how a rainbow is formed.",
    "What is the speed of light?",
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
# [CORE] PERSISTENT MANAGER (MULTI-ACCOUNT & ANTI-BOT)
# ==================================================================================
class GeminiManager:
    def __init__(self):
        self.client = None
        self.chat = None
        self.chat_request_count = 0  # [NEW] Track requests per session
        self.MAX_CHAT_TURNS = 30     # [NEW] Limit before rotation

        self.loop = asyncio.new_event_loop()
        self.async_lock = asyncio.Lock()

        # --- [CONFIG] ACCOUNT MANAGEMENT ---
        self.cookie_files = ["gemini_cookies.json"]
        self.rate_limit_log = "rate_limit_events.log"
        self.current_account_index = 0

        # Logging / ID helpers
        self.query_counter = 0
        self.log_lock = Lock()

        # Timeouts
        self.generation_timeout = 100
        self.total_timeout = 300

        # --- CIRCUIT BREAKER ---
        self.is_rate_limited = False
        self.rate_limit_resume_time = 0

        # Start the background event loop
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
            # Reset session on account switch
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
        """Initializes the client if needed."""
        if self.client:
            return

        async with self.async_lock:
            if self.client: return

            # Circuit Breaker Check
            if self.is_rate_limited:
                if time.time() < self.rate_limit_resume_time:
                    wait_time = int(self.rate_limit_resume_time - time.time())
                    raise Exception(f"System cooling down. Waiting {wait_time}s.")
                else:
                    self.is_rate_limited = False

            target_cookie_file = self._get_current_cookie_file()

            if not os.path.exists(target_cookie_file):
                 print(f"[CRITICAL] Cookie file missing: {target_cookie_file}")
                 if self.current_account_index != 0:
                     self.current_account_index = 0
                     return await self._ensure_client()
                 raise FileNotFoundError(f"Missing {target_cookie_file}")

            try:
                with open(target_cookie_file, 'r') as f:
                    raw = json.load(f)
                cookies = {c['name']: c['value'] for c in raw if 'name' in c} if isinstance(raw, list) else raw

                self.client = GeminiClient(
                    secure_1psid=cookies.get("__Secure-1PSID"),
                    secure_1psidts=cookies.get("__Secure-1PSIDTS")
                )

                # UA Rotation
                user_agents = [
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
                    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36"
                ]
                if hasattr(self.client, "session"):
                    self.client.session.headers["User-Agent"] = random.choice(user_agents)
                    self.client.session.headers["Referer"] = "https://gemini.google.com/"

                for k, v in cookies.items():
                    if k not in self.client.cookies:
                        self.client.cookies[k] = v

                await self.client.init(timeout=40)

                # Reset chat state on fresh client init
                self.chat = None
                self.chat_request_count = 0
                print(f"[SYSTEM] Client Initialized (Account: {self.current_account_index})")

            except Exception as e:
                print(f"[INIT ERROR] {e}")
                self.client = None
                raise

    async def _execute_with_retry(self, prompt, q_id):
        # 1. Circuit Breaker Check
        if self.is_rate_limited:
            remaining = self.rate_limit_resume_time - time.time()
            if remaining > 0:
                return f"Error: System cooling down ({int(remaining)}s remaining)."
            else:
                self.is_rate_limited = False

        # 2. Jitter
        jitter = random.uniform(4, 8)
        await asyncio.sleep(jitter)

        attempts = 0
        max_attempts = 2

        while attempts < max_attempts:
            try:
                await self._ensure_client()

                # [LOGIC] 30-Request Rotation Strategy
                # If chat is missing OR we hit the limit, start fresh.
                if self.chat is None or self.chat_request_count >= self.MAX_CHAT_TURNS:
                    reason = "Limit Reached" if self.chat else "New Session"
                    print(f"[SYSTEM #{q_id}] 🔄 Rotating Chat Session ({reason})...")
                    self.chat = self.client.start_chat()
                    self.chat_request_count = 0

                # Increment counter BEFORE sending (safe in single-threaded event loop)
                self.chat_request_count += 1

                # Use local reference to avoid race conditions if reset happens during await
                active_chat = self.chat

                response = await asyncio.wait_for(
                    active_chat.send_message(prompt),
                    timeout=self.generation_timeout
                )
                return response.text

            except asyncio.TimeoutError:
                print(f"[WARN #{q_id}] Timeout")
                attempts += 1

            except Exception as e:
                error_str = str(e).lower()
                print(f"[ERROR #{q_id}] {e}")

                # CASE A: Rate Limit (429)
                if "429" in error_str or "too many requests" in error_str:
                    print(f"[ALERT #{q_id}] 🛑 429 Rate Limit!")
                    self._log_rate_limit(q_id)

                    if self._rotate_account():
                        print(f"[SYSTEM #{q_id}] ♻️ Switched Account. Retrying...")
                        async with self.async_lock:
                            self.client = None
                            self.chat = None # Force reset
                        attempts += 1
                        continue
                    else:
                        self.is_rate_limited = True
                        self.rate_limit_resume_time = time.time() + 1200 # 20 min cool down
                        return "Error: Rate limit reached. Pausing for 20 minutes."

                # CASE B: Session Invalid
                elif "auth" in error_str or "login" in error_str or "session" in error_str:
                    print(f"[WARN #{q_id}] Session Invalid. Resetting...")
                    async with self.async_lock:
                        self.client = None
                        self.chat = None # Force reset
                        self.chat_request_count = 0

                # CASE C: Server Error
                elif "500" in error_str:
                     await asyncio.sleep(10)

                attempts += 1
                await asyncio.sleep(5)

        return "Error: Failed to generate response after retries."

    def query(self, prompt):
        """Thread-safe entry point."""
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

# --- STRESS TESTING THREAD ---
STRESS_TEST_RUNNING = False

def run_stress_test_loop():
    global STRESS_TEST_RUNNING
    print("\n[TEST] 🧪 STARTED: Stress Testing for Rate Limits...")

    while STRESS_TEST_RUNNING:
        if bot_manager.is_rate_limited:
            print("[TEST] 🛑 System reported Rate Limit! Test stopping.")
            break

        prompt = random.choice(TEST_PROMPTS)
        print(f"[TEST] Sending: '{prompt}'")

        result = bot_manager.query(prompt)

        if "Rate limit reached" in result:
            print("[TEST] 🛑 Received Rate Limit Error from Manager. Stopping.")
            break

        time.sleep(1)

    STRESS_TEST_RUNNING = False
    print("[TEST] 🏁 ENDED: Stress Test Complete.")

@app.route('/api/test-limit', methods=['POST'])
def api_test_limit():
    global STRESS_TEST_RUNNING

    if STRESS_TEST_RUNNING:
        return jsonify({"status": "error", "message": "Test already running"}), 400

    STRESS_TEST_RUNNING = True
    thread = Thread(target=run_stress_test_loop, daemon=True)
    thread.start()

    return jsonify({
        "status": "success",
        "message": "Stress test started. Watch server console and 'rate_limit_events.log'."
    })

@app.route('/api/stop-test', methods=['POST'])
def api_stop_test():
    global STRESS_TEST_RUNNING
    STRESS_TEST_RUNNING = False
    return jsonify({"status": "success", "message": "Stopping test..."})

# --- STANDARD API ---
QUOTA_LOCK = Lock()
SESSION_COUNTER = 0
BLOCK_EXPIRATION = 0
MAX_REQUESTS = 50
COOLDOWN_SECONDS = 1000

@app.route('/api/ask-gpt', methods=['POST'])
def api_ask():
    global SESSION_COUNTER, BLOCK_EXPIRATION

    print(f"\n[API] 📥 New Request Received at {time.strftime('%X')}")

    with QUOTA_LOCK:
        current_time = time.time()

        if BLOCK_EXPIRATION > 0 and current_time < BLOCK_EXPIRATION:
            print(f"[API] ⛔ Blocked: Cooldown active ({int(BLOCK_EXPIRATION - current_time)}s remaining)")
            return jsonify({}), 200

        if BLOCK_EXPIRATION > 0 and current_time >= BLOCK_EXPIRATION:
            SESSION_COUNTER = 0
            BLOCK_EXPIRATION = 0
            print("[SYSTEM] 🟢 Server Block Expired. Counter reset.")

        SESSION_COUNTER += 1
        print(f"[API] Request #{SESSION_COUNTER} (Limit: {MAX_REQUESTS})")

        if SESSION_COUNTER >= MAX_REQUESTS:
            BLOCK_EXPIRATION = current_time + COOLDOWN_SECONDS
            print(f"[API] 🚨 MAX_REQUESTS REACHED! Blocking incoming traffic for 16 min.")
            return jsonify({}), 200

    try:
        data = request.get_json(silent=True) or {}
        prompt = data.get('prompt') or data.get('question')
        if not prompt: return jsonify({"error": "Missing prompt"}), 400
        result = bot_manager.query(prompt)
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
<head><title>Gemini API (Safe Mode)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-5 bg-light">
    <div class="container" style="max-width: 800px;">
        <h2 class="mb-4">🤖 Gemini API</h2>

        <div class="card p-3 mb-4">
            <h5>🧪 Stress Test Control</h5>
            <div class="d-flex gap-2">
                <button onclick="fetch('/api/test-limit', {method:'POST'}).then(r=>r.json()).then(d=>alert(d.message))" class="btn btn-warning">Start Stress Test</button>
                <button onclick="fetch('/api/stop-test', {method:'POST'}).then(r=>r.json()).then(d=>alert(d.message))" class="btn btn-danger">Stop Test</button>
            </div>
            <small class="text-muted mt-2">Check console for live progress.</small>
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