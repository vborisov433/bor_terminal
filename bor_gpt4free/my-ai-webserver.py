import traceback
import sys
import os
import asyncio
import json
import time
import logging
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
# [CORE] PERSISTENT MANAGER WITH CIRCUIT BREAKER
# ==================================================================================
class GeminiManager:
    def __init__(self):
        self.client = None
        self.loop = asyncio.new_event_loop()
        self.async_lock = asyncio.Lock()
        self.cookie_file = "gemini_cookies.json"

        # Logging / ID helpers
        self.query_counter = 0
        self.log_lock = Lock()

        # Configuration
        self.generation_timeout = 100
        self.total_timeout = 300

        # --- 429 CIRCUIT BREAKER ---
        self.is_rate_limited = False
        self.rate_limit_resume_time = 0

        # Start the background event loop
        self.thread = Thread(target=self._run_event_loop, daemon=True)
        self.thread.start()

    def _run_event_loop(self):
        """Runs the asyncio loop in a separate thread forever."""
        asyncio.set_event_loop(self.loop)
        self.loop.run_forever()

    async def _ensure_client(self):
        """Initializes the client if needed, respecting rate limits."""
        if self.client:
            return

        async with self.async_lock:
            if self.client:
                return

            # [CRITICAL] Stop initialization if we are in the penalty box
            if self.is_rate_limited and time.time() < self.rate_limit_resume_time:
                wait_time = int(self.rate_limit_resume_time - time.time())
                raise Exception(f"Rate limit active. Waiting {wait_time}s cooldown.")

            print(f"[SYSTEM] Initializing new Gemini Client...")

            if not os.path.exists(self.cookie_file):
                print(f"[CRITICAL] Cookie file NOT FOUND at: {os.path.abspath(self.cookie_file)}")
                raise FileNotFoundError(f"Missing {self.cookie_file}")

            try:
                with open(self.cookie_file, 'r') as f:
                    raw = json.load(f)

                # Handle both list (EditThisCookie) and dict formats
                cookies = {c['name']: c['value'] for c in raw if 'name' in c} if isinstance(raw, list) else raw

                self.client = GeminiClient(
                    secure_1psid=cookies.get("__Secure-1PSID"),
                    secure_1psidts=cookies.get("__Secure-1PSIDTS")
                )

                # Load remaining cookies
                for k, v in cookies.items():
                    if k not in self.client.cookies:
                        self.client.cookies[k] = v

                await self.client.init(timeout=40)
                print("[SYSTEM] Gemini Client Successfully Initialized ✅")

                # Reset circuit breaker on successful init
                self.is_rate_limited = False

            except Exception as e:
                print(f"[INIT ERROR] Failed to initialize GeminiClient: {e}")
                self.client = None
                raise

    async def _execute_with_retry(self, prompt, q_id):
        # 1. Check Circuit Breaker
        if self.is_rate_limited:
            remaining = self.rate_limit_resume_time - time.time()
            if remaining > 0:
                return f"Error: System is cooling down due to high traffic. Try again in {int(remaining)} seconds."
            else:
                self.is_rate_limited = False
                print(f"[SYSTEM #{q_id}] Cooldown expired. Resuming operations.")

        attempts = 0
        max_attempts = 2

        while attempts < max_attempts:
            try:
                await self._ensure_client()

                response = await asyncio.wait_for(
                    self.client.generate_content(prompt),
                    timeout=self.generation_timeout
                )
                return response.text

            except asyncio.TimeoutError:
                print(f"[WARN #{q_id}] Timeout (Attempt {attempts+1})")
                attempts += 1

            except Exception as e:
                error_str = str(e).lower()

                # [CRITICAL] 429 DETECTOR
                if "429" in error_str or "too many requests" in error_str:
                    print(f"[ALERT #{q_id}] 429 Rate Limit Detected! Pausing 5 mins. 🛑")
                    self.is_rate_limited = True
                    self.rate_limit_resume_time = time.time() + 360 # 5 Minute Pause
                    return "Error: Rate limit reached. The system is pausing for 5 minutes."

                print(f"[ERROR #{q_id}] Attempt {attempts+1} failed: {e}")

                # Only reset client if it looks like an auth/connection issue
                # NOT if it's a rate limit issue
                if "auth" in error_str or "login" in error_str or "cookie" in error_str:
                    async with self.async_lock:
                        self.client = None

                attempts += 1
                await asyncio.sleep(2)

        return "Error: Failed to generate response after retries."

    def query(self, prompt):
        """Thread-safe entry point for Flask to call."""
        with self.log_lock:
            self.query_counter += 1
            q_id = self.query_counter

        clean_prompt = prompt.strip()
        print(f"\n[QUERY #{q_id}] Processing...")

        future = asyncio.run_coroutine_threadsafe(
            self._execute_with_retry(clean_prompt, q_id),
            self.loop
        )

        try:
            result = future.result(timeout=self.total_timeout)
            if result.startswith("Error"):
                print(f"[STATUS #{q_id}] Failed ❌")
            else:
                print(f"[STATUS #{q_id}] Success ✅")
            return result
        except Exception as e:
            print(f"[STATUS #{q_id}] CRITICAL TIMEOUT/FAIL: {e} ❌")
            return f"Error: Request processing failed ({str(e)})"

# ==================================================================================
# [FLASK] APP SETUP
# ==================================================================================
app = Flask(__name__)
log = logging.getLogger('werkzeug')
log.setLevel(logging.ERROR)

# Initialize Global Manager
bot_manager = GeminiManager()

# --- SERVER-SIDE RATE LIMIT CONFIG ---
QUOTA_LOCK = Lock()
SESSION_COUNTER = 0
BLOCK_EXPIRATION = 0
MAX_REQUESTS = 50
COOLDOWN_SECONDS = 3600

@app.route('/api/ask-gpt', methods=['POST'])
def api_ask():
    global SESSION_COUNTER, BLOCK_EXPIRATION

    # --- QUOTA CHECK ---
    with QUOTA_LOCK:
        current_time = time.time()

        if current_time < BLOCK_EXPIRATION:
            return jsonify({}), 200

        if BLOCK_EXPIRATION > 0 and current_time >= BLOCK_EXPIRATION:
            SESSION_COUNTER = 0
            BLOCK_EXPIRATION = 0
            print("[SYSTEM] Server Block Expired. Counter reset.")

        SESSION_COUNTER += 1

        if SESSION_COUNTER >= MAX_REQUESTS:
            BLOCK_EXPIRATION = current_time + COOLDOWN_SECONDS
            print(f"[SYSTEM] Limit reached. Blocking for 1 hour.")
            return jsonify({}), 200

    # --- PROCESS ---
    try:
        data = request.get_json(silent=True) or {}
        prompt = data.get('prompt') or data.get('question')

        if not prompt:
            return jsonify({"error": "Missing prompt"}), 400

        result = bot_manager.query(prompt)

        if result.startswith("Error"):
            return jsonify({"status": "error", "message": result}), 500

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
        <h2 class="mb-4">🤖 Gemini API Interface</h2>
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