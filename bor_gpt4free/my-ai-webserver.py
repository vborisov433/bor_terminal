# ==================================================================================
# [FLASK] APP SETUP
# ==================================================================================
app = Flask(__name__)

log = logging.getLogger('werkzeug')
log.setLevel(logging.ERROR)

# --- RATE LIMIT / QUOTA CONFIGURATION ---
QUOTA_LOCK = Lock()
SESSION_COUNTER = 0       # Tracks total requests
BLOCK_EXPIRATION = 0      # Timestamp when the block ends
MAX_REQUESTS = 50         # Limit before blocking
COOLDOWN_SECONDS = 3600   # 1 Hour in seconds

@app.route('/api/ask-gpt', methods=['POST'])
def api_ask():
    global SESSION_COUNTER, BLOCK_EXPIRATION

    # --- 1. QUOTA CHECK LOGIC ---
    with QUOTA_LOCK:
        current_time = time.time()

        # A. Check if we are currently inside the 1-hour block window
        if current_time < BLOCK_EXPIRATION:
            # Still blocked: Return empty JSON, but status 200
            return jsonify({'rate limit, waiting for next hour'}), 429

        # B. If block time has passed, reset the counter
        if BLOCK_EXPIRATION > 0 and current_time >= BLOCK_EXPIRATION:
            SESSION_COUNTER = 0
            BLOCK_EXPIRATION = 0
            print("[SYSTEM] 1-Hour Block Expired. Counter reset.")

        # C. Increment request counter
        SESSION_COUNTER += 1

        # D. Check if we hit the limit (50th request triggers the block)
        if SESSION_COUNTER >= MAX_REQUESTS:
            # Set expiration to 1 hour from now
            BLOCK_EXPIRATION = current_time + COOLDOWN_SECONDS
            print(f"[SYSTEM] Limit of {MAX_REQUESTS} reached. Blocking for 1 hour.")
            return jsonify({'rate limit, waiting for next hour'}), 429

    # --- 2. NORMAL PROCESSING ---
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
    # ... (Keep the rest of your web_index code exactly as it was)
    answer, error, question = "", "", ""
    if request.method == 'POST':
        question = request.form.get('question', '')
        # Note: The web UI bypasses the API quota logic above.
        # If you want the UI to also be limited, you need to add the check here too.
        answer = bot_manager.query(question)
        if answer.startswith("Error"):
            error, answer = answer, ""

    return render_template_string(HTML_TEMPLATE, answer=answer, question=question, error=error)