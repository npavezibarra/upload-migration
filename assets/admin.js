(function () {
  function el(id) {
    return document.getElementById(id);
  }

  function formatBytes(bytes) {
    if (!bytes || bytes <= 0) return "0 B";
    const units = ["B", "KB", "MB", "GB", "TB"];
    let i = 0;
    let v = bytes;
    while (v >= 1024 && i < units.length - 1) {
      v /= 1024;
      i++;
    }
    return `${v.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
  }

  async function post(action, data) {
    const body = new URLSearchParams();
    body.set("action", action);
    body.set("nonce", UploadsMigration.nonce);
    Object.keys(data || {}).forEach((k) => body.set(k, data[k]));
    const res = await fetch(UploadsMigration.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body,
    });
    const text = await res.text();
    let json;
    try {
      json = JSON.parse(text);
    } catch (e) {
      const snippet = (text || "").slice(0, 180).replace(/\s+/g, " ").trim();
      throw new Error(
        `Server returned non-JSON response (often an error page, 413 upload limit, or a PHP fatal). Snippet: ${snippet}`
      );
    }
    if (!json || !json.success) {
      throw new Error((json && json.data && json.data.error) || "Request failed");
    }
    return json.data;
  }

  async function uploadArchive(file) {
    const form = new FormData();
    form.append("action", "uploads_migration_upload_archive");
    form.append("nonce", UploadsMigration.nonce);
    form.append("archive", file, file.name);

    const res = await fetch(UploadsMigration.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: form,
    });
    const text = await res.text();
    let json;
    try {
      json = JSON.parse(text);
    } catch (e) {
      const snippet = (text || "").slice(0, 180).replace(/\s+/g, " ").trim();
      throw new Error(
        `Upload returned non-JSON response (often an error page or upload size limit). Snippet: ${snippet}`
      );
    }
    if (!json || !json.success) {
      throw new Error((json && json.data && json.data.error) || "Upload failed");
    }
    return json.data.token;
  }

  function renderExportProgress(state) {
    const container = el("uploads-migration-export-progress");
    if (!container) return;
    const total = state.total_files || 0;
    const done = state.processed_files || 0;
    const pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
    container.innerHTML = `
      <div style="border:1px solid #ccd0d4; height:18px; position:relative; background:#fff;">
        <div style="width:${pct}%; height:18px; background:#2271b1;"></div>
        <div style="position:absolute; top:0; left:0; right:0; height:18px; line-height:18px; text-align:center; font-size:12px; color:#111;">
          ${pct}% (${done}/${total} files, ${formatBytes(state.processed_bytes)} / ${formatBytes(state.total_bytes)})
        </div>
      </div>`;
  }

  function renderImportProgress(state) {
    const container = el("uploads-migration-import-progress");
    if (!container) return;
    const total = state.total_entries || 0;
    const idx = state.current_index || 0;
    const pct = total > 0 ? Math.min(100, Math.round((idx / total) * 100)) : 0;
    container.innerHTML = `
      <div style="border:1px solid #ccd0d4; height:18px; position:relative; background:#fff;">
        <div style="width:${pct}%; height:18px; background:#2271b1;"></div>
        <div style="position:absolute; top:0; left:0; right:0; height:18px; line-height:18px; text-align:center; font-size:12px; color:#111;">
          ${total ? `${pct}% (${idx}/${total} entries)` : `Processed ${idx} entries`}
        </div>
      </div>`;
  }

  function renderImportSummary(state) {
    const summaryEl = el("uploads-migration-import-summary");
    if (!summaryEl) return;
    const s = state.summary || {};
    summaryEl.innerHTML = `
      <p><strong>Summary</strong></p>
      <ul>
        <li>Imported: ${s.imported || 0}</li>
        <li>Skipped: ${s.skipped || 0}</li>
        <li>Overwritten: ${s.overwritten || 0}</li>
        <li>Errors: ${s.errors || 0}</li>
      </ul>`;
  }

  async function runExport() {
    const status = el("uploads-migration-export-status");
    const download = el("uploads-migration-export-download");
    try {
      status.textContent = "Scanning and starting...";
      if (download) download.style.display = "none";

      const { state: initialState } = await post("uploads_migration_start_export", {});
      let state = initialState;
      renderExportProgress(state);
      status.textContent = "Exporting...";

      while (true) {
        const data = await post("uploads_migration_export_batch", { id: state.id });
        state = data.state;
        renderExportProgress(state);
        if (data.done) {
          status.textContent = "Done.";
          if (download) {
            download.style.display = "block";
            if (data.downloadUrls && data.downloadUrls.length) {
              const links = data.downloadUrls
                .map(
                  (p) =>
                    `<li><a class="button button-secondary" href="${p.url}">Download ${p.file}</a> <span style="opacity:.8">(${p.files} files, ${formatBytes(
                      p.bytes
                    )})</span></li>`
                )
                .join("");
              download.innerHTML = `<p><strong>Archive parts</strong> (upload each part on live)</p><ul>${links}</ul>`;
            } else {
              download.innerHTML = "";
            }
          }
          break;
        }
      }
    } catch (e) {
      status.textContent = `Error: ${e.message}`;
    }
  }

  async function runImport() {
    const status = el("uploads-migration-import-status");
    const fileInput = el("uploads-migration-archive");
    const overwrite = el("uploads-migration-overwrite");
    try {
      if (!fileInput || !fileInput.files || !fileInput.files.length) {
        throw new Error("Choose one or more archive parts first.");
      }
      const files = Array.from(fileInput.files);
      const doOverwrite = overwrite && overwrite.checked ? "1" : "";

      const grand = { imported: 0, skipped: 0, overwritten: 0, errors: 0 };

      for (let i = 0; i < files.length; i++) {
        const f = files[i];
        status.textContent = `Uploading part ${i + 1}/${files.length}...`;
        const token = await uploadArchive(f);

        status.textContent = `Starting import part ${i + 1}/${files.length}...`;
        const { state: initialState } = await post("uploads_migration_start_import", {
          token,
          overwrite: doOverwrite,
        });
        let state = initialState;

        renderImportProgress(state);
        renderImportSummary(state);
        status.textContent = `Importing part ${i + 1}/${files.length}...`;

        while (true) {
          const data = await post("uploads_migration_import_batch", { id: state.id });
          state = data.state;
          renderImportProgress(state);
          renderImportSummary(state);
          if (data.done) {
            break;
          }
        }

        const s = state.summary || {};
        grand.imported += s.imported || 0;
        grand.skipped += s.skipped || 0;
        grand.overwritten += s.overwritten || 0;
        grand.errors += s.errors || 0;

        const summaryEl = el("uploads-migration-import-summary");
        if (summaryEl) {
          summaryEl.innerHTML += `<p><strong>Totals so far</strong>: Imported ${grand.imported}, Skipped ${grand.skipped}, Overwritten ${grand.overwritten}, Errors ${grand.errors}</p>`;
        }
      }

      status.textContent = `Done. Imported ${grand.imported}, skipped ${grand.skipped}, overwritten ${grand.overwritten}, errors ${grand.errors}.`;
    } catch (e) {
      status.textContent = `Error: ${e.message}`;
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    const exportBtn = el("uploads-migration-start-export");
    if (exportBtn) exportBtn.addEventListener("click", runExport);

    const importBtn = el("uploads-migration-start-import");
    if (importBtn) importBtn.addEventListener("click", runImport);
  });
})();
