import traceback
import sys
import os
import asyncio
import json
import time
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
    print(f"[CRITICAL] Missing folder: {gemini_src_path}")
    sys.exit(1)

try:
    from gemini_webapi import GeminiClient
    import gemini_webapi.utils
    gemini_webapi.utils.load_browser_cookies = lambda: {}
except ImportError as e:
    print(f"[CRITICAL] Import failed: {e}")
    sys.exit(1)

# ==================================================================================
# [CORE] PERSISTENT & SELF-HEALING MANAGER
# ==================================================================================
class GeminiManager:
    def __init__(self):
        self.client = None
        self.loop = asyncio.new_event_loop()
        # Use asyncio.Lock for internal state safety, not threading.Lock
        self.async_lock = asyncio.Lock()
        self.cookie_file = "gemini_cookies.json"

        # Configuration
        self.generation_timeout = 50  # Time for one attempt
        self.total_timeout = 110      # Max time Flask waits (allows for 1 retry)

        self.thread = Thread(target=self._run_event_loop, daemon=True)
        self.thread.start()

    def _run_event_loop(self):
        asyncio.set_event_loop(self.loop)
        self.loop.run_forever()

    async def _ensure_client(self):
        """Ensures client is active. Must be called within the loop."""
        if self.client:
            return

        if not os.path.exists(self.cookie_file):
            raise FileNotFoundError(f"Missing {self.cookie_file}")

        print("[SYS] Initializing Gemini Client...")
        with open(self.cookie_file, 'r') as f:
            raw = json.load(f)

        # Normalize cookies (handle list vs dict format)
        cookies = {c['name']: c['value'] for c in raw if 'name' in c} if isinstance(raw, list) else raw

        self.client = GeminiClient(
            secure_1psid=cookies.get("__Secure-1PSID"),
            secure_1psidts=cookies.get("__Secure-1PSIDTS")
        )

        # Load extra cookies
        for k, v in cookies.items():
            if k not in self.client.cookies:
                self.client.cookies[k] = v

        await self.client.init(timeout=30)
        print("[SYS] Gemini Client Ready.")

    async def _execute_with_retry(self, prompt):
        """Logic running inside the asyncio loop."""
        # Acquire async lock only during client init/access, not the whole generation
        # NOTE: If the library does not support concurrent generate_content,
        # keep the lock for the whole block. Assuming aiohttp-based, it usually supports it.

        attempts = 0
        max_attempts = 2

        while attempts < max_attempts:
            try:
                # 1. Ensure Client Exists
                async with self.async_lock:
                    await self._ensure_client()

                # 2. Generate (Allow concurrency here if library supports it)
                print(f"[ASYNC] sending query (Attempt {attempts+1})...")
                response = await asyncio.wait_for(
                    self.client.generate_content(prompt),
                    timeout=self.generation_timeout
                )
                return response.text

            except asyncio.TimeoutError:
                print(f"[WARN] Timeout on attempt {attempts+1}")
                attempts += 1
            except Exception as e:
                print(f"[ERR] Error on attempt {attempts+1}: {e}")
                # Only force reset on non-timeout errors (potential auth issues)
                async with self.async_lock:
                    self.client = None # Force re-init next loop
                attempts += 1
                await asyncio.sleep(1) # Cool down

        return "Error: Failed to generate response after retries."

    def query(self, prompt):
        """Thread-safe entry point. Non-blocking for other requests."""
        # Submit task to background loop
        future = asyncio.run_coroutine_threadsafe(
            self._execute_with_retry(prompt),
            self.loop
        )

        try:
            # Flask thread waits here, but DOES NOT block other Flask threads
            return future.result(timeout=self.total_timeout)
        except Exception as e:
            return f"Error: Request processing failed ({str(e)})"

# Global Instance
bot_manager = GeminiManager()

# ==================================================================================
# [FLASK] APP SETUP
# ==================================================================================
app = Flask(__name__)

# Simple Rate Limiter
request_history = []
LIMIT_LOCK = Lock()
RATE_LIMIT = 5
WINDOW = 60

@app.route('/api/ask-gpt', methods=['POST'])
def api_ask():
    # 1. Rate Limiting
    with LIMIT_LOCK:
        now = time.time()
        request_history[:] = [t for t in request_history if t > now - WINDOW]
        if len(request_history) >= RATE_LIMIT:
            return jsonify({"status": "error", "message": "Rate limit exceeded"}), 429
        request_history.append(now)

    # 2. Logic
    try:
        data = request.get_json(silent=True) or {}
        prompt = data.get('prompt') or data.get('question')
        if not prompt:
            return jsonify({"error": "Missing prompt"}), 400

        print(f"[API] Query: {prompt[:50]}...")
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
<head><title>Gemini Persistent</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-5 bg-light">
    <div class="container" style="max-width: 800px;">
        <h2 class="mb-4">🤖 Gemini Persistent Session</h2>
        <form method="post">
            <textarea name="question" class="form-control mb-3" rows="5" placeholder="Ask something...">{{question}}</textarea>
            <button class="btn btn-primary w-100">Send Request</button>
        </form>
        {% if answer %}<div class="card mt-4 shadow-sm"><div class="card-body"><pre style="white-space: pre-wrap;">{{answer}}</pre></div></div>{% endif %}
        {% if error %}<div class="alert alert-danger mt-4">{{error}}</div>{% endif %}
    </div>
</body>
</html>
"""

if __name__ == '__main__':
    # CRITICAL: use debug=False to prevent the background thread from being created twice
    print("[SYS] Server starting at http://localhost:5000")
    app.run(host='0.0.0.0', port=5000, debug=False)