/**
 * Communication > Messages > Templates (2026-10-08): the template library,
 * writer and previews of Settings > Communication (settings/sections/
 * templates.js), on their own page - set up by comms-env.js.
 */
document.addEventListener("DOMContentLoaded", async () => {
  const host = document.getElementById("tplHost");
  host.innerHTML = '<div class="row g-3">' + DemographicsUI.skeletonCards(3, "col-md-4") + "</div>";
  await window.CommsTemplates.load();
  window.CommsTemplates.mount(host, {
    onCount: (list) => {
      const el = document.getElementById("tplCount");
      if (el) el.textContent = `${list.length} ready`;
    },
  });
});
