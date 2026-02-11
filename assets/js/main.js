(function () {
  const year = document.getElementById("year");
  if (year) year.textContent = String(new Date().getFullYear());

  const toggle = document.querySelector(".nav-toggle");
  const list = document.getElementById("navList");
  if (toggle && list) {
    toggle.addEventListener("click", () => {
      const open = list.classList.toggle("is-open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });

    list.querySelectorAll("a").forEach((a) => {
      a.addEventListener("click", () => {
        list.classList.remove("is-open");
        toggle.setAttribute("aria-expanded", "false");
      });
    });
  }

  const items = document.querySelectorAll(".reveal");
  if (items.length) {
    const io = new IntersectionObserver(
      (entries) => {
        entries.forEach((e) => {
          if (e.isIntersecting) e.target.classList.add("is-visible");
        });
      },
      { threshold: 0.15 }
    );
    items.forEach((el) => io.observe(el));
  }
})();

// Offer Slider (scroll-snap + buttons + dots + drag support)
(() => {
  const track = document.querySelector("[data-slider-track]");
  if (!track) return;

  const slides = Array.from(track.querySelectorAll("[data-slide]"));
  const btnPrev = document.querySelector("[data-slider-prev]");
  const btnNext = document.querySelector("[data-slider-next]");
  const dotsWrap = document.querySelector("[data-slider-dots]");

  // Create dots
  const dots = slides.map((_, i) => {
    const b = document.createElement("button");
    b.className = "slider-dot";
    b.type = "button";
    b.setAttribute("aria-label", `Zu Angebot ${i + 1}`);
    b.addEventListener("click", () => {
      slides[i].scrollIntoView({ behavior: "smooth", inline: "start", block: "nearest" });
    });
    dotsWrap?.appendChild(b);
    return b;
  });

  const setActive = (index) => {
    dots.forEach((d, i) => d.setAttribute("aria-current", i === index ? "true" : "false"));
  };

  const getActiveIndex = () => {
    const trackRect = track.getBoundingClientRect();
    const centerX = trackRect.left + trackRect.width * 0.35;
    let best = 0;
    let bestDist = Infinity;

    slides.forEach((s, i) => {
      const r = s.getBoundingClientRect();
      const dist = Math.abs((r.left + r.width / 2) - centerX);
      if (dist < bestDist) { bestDist = dist; best = i; }
    });
    return best;
  };

  const scrollToIndex = (i) => {
    const clamped = Math.max(0, Math.min(slides.length - 1, i));
    slides[clamped].scrollIntoView({ behavior: "smooth", inline: "start", block: "nearest" });
  };

  btnPrev?.addEventListener("click", () => scrollToIndex(getActiveIndex() - 1));
  btnNext?.addEventListener("click", () => scrollToIndex(getActiveIndex() + 1));

  // Update dots on scroll
  let ticking = false;
  track.addEventListener("scroll", () => {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(() => {
      setActive(getActiveIndex());
      ticking = false;
    });
  }, { passive: true });

  // initial
  setActive(0);

  // --- Drag support (desktop) while keeping links/buttons clickable ---
  let isDown = false;
  let startX = 0;
  let scrollLeft = 0;
  let moved = false;

  const endDrag = () => { isDown = false; };

  track.addEventListener("pointerdown", (e) => {
    // Klick auf interaktive Elemente soll immer funktionieren
    if (e.target.closest("a, button, input, textarea, select, label")) return;

    // Touch: natives Wischen/Scrollen nutzen (keine Pointer-Capture)
    if (e.pointerType === "touch") return;

    isDown = true;
    moved = false;
    startX = e.clientX;
    scrollLeft = track.scrollLeft;

    // pointer capture für sauberes Dragging mit Maus
    try { track.setPointerCapture(e.pointerId); } catch(_) {}
  });

  track.addEventListener("pointermove", (e) => {
    if (!isDown) return;
    const dx = e.clientX - startX;

    if (Math.abs(dx) > 6) moved = true; // etwas höherer Threshold
    track.scrollLeft = scrollLeft - dx;
  });

  track.addEventListener("pointerup", endDrag);
  track.addEventListener("pointercancel", endDrag);
  track.addEventListener("pointerleave", endDrag);

  // Ghost clicks verhindern – aber NUR wenn wirklich gezogen wurde
  track.addEventListener("click", (e) => {
    if (!moved) return;
    // Wenn Klick auf interaktives Element: NICHT blocken (sonst Button tot)
    if (e.target.closest("a, button")) return;

    e.preventDefault();
    e.stopPropagation();
    moved = false;
  }, true);
})();

// Kontaktformular: Angebot aus URL übernehmen und Nachricht vorfüllen
(() => {
  const params = new URLSearchParams(window.location.search);
  const angebot = params.get("angebot");
  if (!angebot) return;

  const messageEl = document.getElementById("message");
  if (!messageEl) return;

  // Optional: Personenfeld nutzen, falls vorhanden
  const personsEl = document.getElementById("people");
  const personsFromUrl = params.get("personen"); // optional ?personen=20
  if (personsEl && personsFromUrl && !personsEl.value) personsEl.value = personsFromUrl;

  const preis = params.get("preis"); // optional
  const preisText = preis ? ` (Preis: ${preis} € p. P.)` : "";

  // Nur befüllen, wenn noch leer (damit Nutzertext nicht überschrieben wird)
  if (!messageEl.value.trim()) {
    messageEl.value =
`Hallo Grazing Tables Saar,

ich möchte gerne eine Grazing Table „${angebot}“${preisText} anfragen.

Besondere Wünsche (z. B. vegetarisch, ohne Schwein, Allergien):

Vielen Dank und viele Grüße
`;
  }
})();

// Kontaktformular: Erfolgsmeldung anzeigen oder Formular anzeigen
(() => {
  const params = new URLSearchParams(window.location.search);
  const successEl = document.getElementById("successMessage");
  const formContainer = document.getElementById("formContainer");
  const sectionHeader = document.querySelector(".section-header");

  if (params.get("sent") === "1" && successEl && formContainer) {
    formContainer.style.display = "none";
    if (sectionHeader) sectionHeader.style.display = "none";
    successEl.style.display = "";
  }
})();

// Kontaktformular: Timestamp setzen (Spam-Check)
(() => {
  const ts = document.getElementById("form_ts");
  if (ts) ts.value = String(Math.floor(Date.now() / 1000));
})();
