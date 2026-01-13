import traceback
import sys
import os
import asyncio
import json
import time
from threading import Lock, Thread
from flask import Flask, request, render_template_string, jsonify

# ==================================================================================
# [SETUP] PATH & IMPORTS
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
    # Disable automatic browser loading to rely solely on our JSON file
    gemini_webapi.utils.load_browser_cookies = lambda: {}
except ImportError as e:
    print(f"[CRITICAL] Import failed: {e}")
    sys.exit(1)

# ==================================================================================
# [CORE] PERSISTENT GEMINI MANAGER
# ==================================================================================
class GeminiManager:
    def __init__(self):
        self.client = None
        self.loop = asyncio.new_event_loop()
        self.lock = Lock()
        self.cookie_file = "gemini_cookies.json"
        self.default_timeout = 120

        # Start the background event loop thread
        self.thread = Thread(target=self._run_event_loop, daemon=True)
        self.thread.start()

    def _run_event_loop(self):
        asyncio.set_event_loop(self.loop)
        self.loop.run_forever()

    async def _init_client_async(self):
        """Internal: Initializes the client if not already active."""
        if self.client:
            return

        if not os.path.exists(self.cookie_file):
            raise Exception(f"'{self.cookie_file}' missing.")

        with open(self.cookie_file, 'r') as f:
            raw = json.load(f)

        file_cookies = {c['name']: c['value'] for c in raw if 'name' in c} if isinstance(raw, list) else raw

        psid = file_cookies.get("__Secure-1PSID")
        psidts = file_cookies.get("__Secure-1PSIDTS")

        if not psid:
            raise Exception("Missing __Secure-1PSID in cookies.")

        print("[SYS] Initializing Persistent Gemini Client...")
        self.client = GeminiClient(secure_1psid=psid, secure_1psidts=psidts)

        # Inject all other cookies from file
        for k, v in file_cookies.items():
            if k not in self.client.cookies:
                self.client.cookies[k] = v

        await self.client.init(timeout=self.default_timeout)
        print("[SYS] Gemini Client Ready.")

    async def _generate_async(self, prompt):
        """Internal: Performs the actual API call."""
        await self._init_client_async()
        try:
            response = await self.client.generate_content(prompt)
            return response.text
        except ValueError:
            return "Error: Google refused content (Safety filter or stale session)."
        except Exception as e:
            return f"ERROR_INTERNAL: {str(e)}"

    def query(self, prompt):
        """Thread-safe entry point for Flask routes."""
        with self.lock:
            future = asyncio.run_coroutine_threadsafe(self._generate_async(prompt), self.loop)
            return future.result(timeout=130)

# Instantiate the global manager
gemini_bot = GeminiManager()

# ==================================================================================
# [FLASK] WEB SERVER
# ==================================================================================
app = Flask(__name__)

request_timestamps = []
request_lock = Lock()
RATE_QUOTA = 3
RATE_WINDOW = 20

HTML_TEMPLATE = '''
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Gemini Web Chat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <h2 class="mb-4">🤖 Gemini Web Chat (Persistent Session)</h2>
    <form method="post">
        <div class="mb-3">
            <label for="question" class="form-label">Enter your question:</label>
            <textarea name="question" id="question" class="form-control" rows="6" required>{{ question }}</textarea>
        </div>
        <button type="submit" class="btn btn-primary">Ask Gemini</button>
    </form>
    {% if answer or error %}
    <div class="mt-5">
        <h4>💡 Response:</h4>
        <div class="card shadow-sm {{ 'border-danger' if error else 'border-success' }}">
            <div class="card-body">
                {% if error %}<p class="text-danger">{{ error }}</p>
                {% else %}<pre style="white-space: pre-wrap;">{{ answer }}</pre>{% endif %}
            </div>
        </div>
    </div>
    {% endif %}
</div>
</body>
</html>
'''

@app.route('/api/ask-gpt', methods=['POST'])
def ask_gpt():
    global request_timestamps
    print("\n[API] Incoming request...")

    # Rate Limiting
    with request_lock:
        now = time.time()
        request_timestamps = [t for t in request_timestamps if t > (now - RATE_WINDOW)]
        if len(request_timestamps) >= RATE_QUOTA:
            wait = int(RATE_WINDOW - (now - request_timestamps[0])) + 1
            return jsonify({"status": "error", "message": f"Wait {wait}s"}), 429
        request_timestamps.append(now)

    prompt = None
    try:
        data = request.get_json(silent=True)
        if not data:
            return jsonify({"error": "Invalid JSON"}), 400

        prompt = data.get('prompt') or data.get('question')
        if not prompt:
            return jsonify({"error": "No prompt provided"}), 400

        # Use the persistent manager
        bot_response = gemini_bot.query(prompt)

        if "ERROR_INTERNAL" in bot_response or bot_response.startswith("Error"):
             return jsonify({"status": "error", "message": bot_response}), 500

        return jsonify({"status": "success", "answer": bot_response})

    except Exception as e:
        print(f"[CRITICAL] {traceback.format_exc()}")
        return jsonify({"status": "error", "message": str(e)}), 500

@app.route('/', methods=['GET', 'POST'])
def index():
    answer, error, question = "", "", ""
    if request.method == 'POST':
        question = request.form['question']
        try:
            answer = gemini_bot.query(question)
            if answer.startswith("Error"):
                error, answer = answer, ""
        except Exception as e:
            error = str(e)
    return render_template_string(HTML_TEMPLATE, answer=answer, question=question, error=error)

if __name__ == '__main__':
    print("[SYS] Server starting at http://0.0.0.0:5000")
    app.run(host='0.0.0.0', debug=False, port=5000) # debug=False recommended for background threads