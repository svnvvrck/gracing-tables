(function () {
  const form = document.getElementById("anfrageForm");
  if (!form) return;

  form.addEventListener("submit", (e) => {
    e.preventDefault();

    const data = new FormData(form);
    const subject = "Anfrage Grazing Table – Grazing Tables Saar";

    const lines = [
      "Hallo Grazing Tables Saar,",
      "",
      "ich möchte unverbindlich anfragen:",
      "",
      `Datum: ${data.get("event_datum") || ""}`,
      `Uhrzeit: ${data.get("event_uhrzeit") || ""}`,
      `Ort: ${data.get("event_ort") || ""}`,
      `Anlass: ${data.get("event_anlass") || ""}`,
      `Personenzahl: ${data.get("personenzahl") || ""}`,
      "",
      `E-Mail: ${data.get("email") || ""}`,
      `Telefon: ${data.get("telefon") || ""}`,
      "",
      "Nachricht:",
      `${data.get("nachricht") || ""}`,
      "",
      "Viele Grüße"
    ];

    const body = encodeURIComponent(lines.join("\n"));
    const mailto = `mailto:info@grazing-tables-saar.de?subject=${encodeURIComponent(subject)}&body=${body}`;

    window.location.href = mailto;
  });
})();
