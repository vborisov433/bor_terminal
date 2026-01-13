import traceback
import sys
import os
import asyncio
import json
import time
from threading import Lock, Thread
from flask import Flask, request, render_template_string, jsonify

# ==================================================================================
# [CORE] SELF-HEALING GEMINI MANAGER
# ==================================================================================
class GeminiManager:
    def __init__(self):
        self.client = None
        self.loop = asyncio.new_event_loop()
        self.lock = Lock()
        self.cookie_file = "gemini_cookies.json"
        self.default_timeout = 100 # Reduced for faster failure detection

        # Background thread for the event loop
        self.thread = Thread(target=self._run_event_loop, daemon=True)
        self.thread.start()

    def _run_event_loop(self):
        asyncio.set_event_loop(self.loop)
        try:
            self.loop.run_forever()
        except Exception as e:
            print(f"[CRITICAL] Event loop crashed: {e}")

    async def _init_client_async(self, force=False):
        """Initializes or repairs the client connection."""
        if self.client and not force:
            return

        if self.client:
            try: await self.client.close()
            except: pass

        if not os.path.exists(self.cookie_file):
            raise FileNotFoundError(f"Missing {self.cookie_file}")

        with open(self.cookie_file, 'r') as f:
            raw = json.load(f)

        cookies = {c['name']: c['value'] for c in raw if 'name' in c} if isinstance(raw, list) else raw

        print("[SYS] Attempting to (re)connect to Gemini...")
        self.client = GeminiClient(
            secure_1psid=cookies.get("__Secure-1PSID"),
            secure_1psidts=cookies.get("__Secure-1PSIDTS")
        )

        # Patch client with all provided cookies
        for k, v in cookies.items():
            if k not in self.client.cookies: self.client.cookies[k] = v

        await self.client.init(timeout=self.default_timeout)
        print("[SYS] Connection established successfully.")

    async def _generate_with_retry(self, prompt):
        """Tries to generate content; re-inits on failure."""
        try:
            if not self.client:
                await self._init_client_async()

            # Wrap API call in wait_for to prevent indefinite hanging
            response = await asyncio.wait_for(
                self.client.generate_content(prompt),
                timeout=self.default_timeout
            )
            return response.text
        except (asyncio.TimeoutError, Exception) as e:
            print(f"[RETRY] Request failed: {e}. Attempting reconnection...")
            try:
                await self._init_client_async(force=True)
                response = await asyncio.wait_for(
                    self.client.generate_content(prompt),
                    timeout=self.default_timeout
                )
                return response.text
            except Exception as final_e:
                return f"Error after retry: {str(final_e)}"

    def query(self, prompt):
        """Thread-safe call with a global timeout for the Flask response."""
        # Check if loop is still running
        if not self.loop.is_running():
            return "Error: Internal event loop is dead."

        with self.lock:
            future = asyncio.run_coroutine_threadsafe(self._generate_with_retry(prompt), self.loop)
            try:
                # Flask will wait a max of 120s before giving up on the manager
                return future.result(timeout=120)
            except Exception as e:
                return f"Error: Request timed out at the manager level ({e})"

# Instantiate global manager
gemini_bot = GeminiManager()

# ==================================================================================
# [FLASK] WEB SERVER
# ==================================================================================
app = Flask(__name__)

# Rate Limiting Settings
request_timestamps = []
request_lock = Lock()
RATE_QUOTA = 5
RATE_WINDOW = 60

@app.route('/api/ask-gpt', methods=['POST'])
def ask_gpt():
    # 1. Rate Limiting
    with request_lock:
        now = time.time()
        request_timestamps[:] = [t for t in request_timestamps if t > (now - RATE_WINDOW)]
        if len(request_timestamps) >= RATE_QUOTA:
            return jsonify({"status": "error", "message": "Rate limit exceeded"}), 429
        request_timestamps.append(now)

    # 2. Process Request
    try:
        data = request.get_json(silent=True) or {}
        prompt = data.get('prompt') or data.get('question')
        if not prompt:
            return jsonify({"error": "No prompt provided"}), 400

        print(f"[API] Processing: {prompt[:50]}...")
        bot_response = gemini_bot.query(prompt)

        if "Error" in bot_response:
             return jsonify({"status": "error", "message": bot_response}), 500

        return jsonify({"status": "success", "answer": bot_response})
    except Exception:
        return jsonify({"status": "error", "message": traceback.format_exc()}), 500

@app.route('/', methods=['GET', 'POST'])
def index():
    answer, error, question = "", "", ""
    if request.method == 'POST':
        question = request.form.get('question', '')
        answer = gemini_bot.query(question)
        if "Error" in answer:
            error, answer = answer, ""

    return render_template_string(HTML_TEMPLATE, answer=answer, question=question, error=error)

HTML_TEMPLATE = """
<!DOCTYPE html>
<html>
<head><title>Gemini Persistent</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-5 bg-light">
    <div class="container">
        <h2>Gemini Persistent Chat</h2>
        <form method="post"><textarea name="question" class="form-control mb-3" rows="5">{{question}}</textarea>
        <button class="btn btn-primary">Ask</button></form>
        {% if answer %}<div class="alert alert-success mt-4"><pre>{{answer}}</pre></div>{% endif %}
        {% if error %}<div class="alert alert-danger mt-4">{{error}}</div>{% endif %}
    </div>
</body>
</html>
"""

if __name__ == '__main__':
    # debug=True can cause double-initialization of background threads.
    # Use debug=False for production-like testing.
    app.run(host='0.0.0.0', port=5000, debug=False)