import time
import json
import os
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from webdriver_manager.chrome import ChromeDriverManager

def save_cookies():
    print("[INFO] Launching Chrome...")

    # Setup Chrome options
    options = webdriver.ChromeOptions()
    # options.add_argument("--headless") # Keep this commented out so you can see the login screen

    # Automatically download and install the correct ChromeDriver
    driver = webdriver.Chrome(service=Service(ChromeDriverManager().install()), options=options)

    try:
        print("[ACTION] Go to the browser window and log in to https://gemini.google.com")
        driver.get("https://gemini.google.com")

        # Wait for user to log in manually
        input(">>> PRESS ENTER HERE AFTER YOU HAVE FULLY LOGGED IN <<<")

        print("[INFO] Extracting cookies...")
        selenium_cookies = driver.get_cookies()

        # Convert to the dictionary format GeminiClient needs
        cookie_dict = {}
        relevant_keys = ["__Secure-1PSID", "__Secure-1PSIDTS", "__Secure-1PSIDCC"]

        for cookie in selenium_cookies:
            # Save all cookies just in case, or filter for specific ones
            if cookie['name'] in relevant_keys:
                cookie_dict[cookie['name']] = cookie['value']
                print(f"[DEBUG] Found: {cookie['name']}")

        # Save to file
        with open("gemini_cookies.json", "w") as f:
            json.dump(cookie_dict, f, indent=4)

        print("[SUCCESS] Cookies saved to 'gemini_cookies.json'. You can now run app.py.")

    except Exception as e:
        print(f"[ERROR] Failed: {e}")
    finally:
        driver.quit()

if __name__ == "__main__":
    save_cookies()