/* Kurage URL2GPTResearcher 管理画面。
 *
 * 直叩き(FastAPIの / )でも、heteml の kurl2gr.php 越しでも同じコードで動く。
 * PHP経由のときは window.KURL2GR_API_PREFIX="?api=" が入り、パスをクエリに畳む。
 */
const $ = (sel) => document.querySelector(sel);
const state = { sites: [], site: null, posts: [], usage: null, billing: null };

async function api(path, options = {}) {
  // 管理者が代理操作中なら、どのAPI呼び出しにも対象利用者を付ける（kgeoと同型）
  const actAs = window.KURL2GR_ACT_AS ? `&as=${encodeURIComponent(window.KURL2GR_ACT_AS)}` : "";
  const target = window.KURL2GR_API_PREFIX
    ? `${window.KURL2GR_API_PREFIX}${encodeURIComponent(path)}${actAs}`
    : path;
  const headers = { "Content-Type": "application/json", ...options.headers };
  if (window.KURL2GR_CSRF) headers["X-CSRF-Token"] = window.KURL2GR_CSRF;
  const response = await fetch(target, { headers, ...options });
  if (!response.ok) {
    let detail = `HTTP ${response.status}`;
    try { detail = (await response.json()).detail || detail; } catch (_) {}
    const error = new Error(detail);
    error.status = response.status;
    throw error;
  }
  return response.json();
}

let toastTimer = null;
function toast(message, bad = false) {
  const box = $("#toast");
  box.textContent = message;
  box.classList.toggle("bad", bad);
  box.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { box.hidden = true; }, bad ? 8000 : 4000);
}

/** ボタンを押しっぱなしの二重実行を防ぎつつ、待ち時間を見せる。 */
async function busy(button, label, task) {
  const original = button.textContent;
  button.disabled = true;
  button.innerHTML = `<span class="spin"></span>${label}`;
  try { return await task(); } finally {
    button.disabled = false;
    button.textContent = original;
  }
}

function fmtDate(value) {
  const d = new Date(value);
  if (isNaN(d)) return "";
  // 表示は日本時間（ワークスペース共通）
  return d.toLocaleString("ja-JP", { timeZone: "Asia/Tokyo", dateStyle: "short", timeStyle: "short" });
}

function usageText() {
  const u = state.usage;
  if (!u) return "";
  if (u.plan === "admin") return "管理者 · 課金対象外";
  const b = state.billing;
  if (b && !b.admin_bypass) return `調査クレジット ${b.credits}`;
  return `今月 ${u.researches_used} 回`;
}

/* --- 課金（調査1回 = 200円 または 20,000 URLAI）------------------------- */

function billingSay(message, ok = false) {
  const box = $("#billingMsg");
  box.textContent = message;
  box.classList.toggle("ok", ok);
}

async function refreshBilling() {
  // PHPゲートウェイ越しのときだけ課金がある（ローカル直叩きは開発用）
  if (!window.KURL2GR_API_PREFIX) return null;
  state.billing = await api("/billing/status");
  $("#usageChip").textContent = usageText();
  $("#buyBtn").hidden = !!state.billing.admin_bypass;
  return state.billing;
}

async function openBilling() {
  const dialog = $("#billingDialog");
  if (!dialog.open) dialog.showModal();
  billingSay("決済情報を確認しています…");
  try {
    const info = await refreshBilling();
    $("#billingCredits").textContent = String(info.credits);
    $("#billingReceiver").textContent = info.urlai_receiver || "-";
    $("#billingMonthly").textContent =
      `継続してお使いの場合は月額 ${info.monthly_jpy.toLocaleString()}円（税別）のプランを準備中です。`;
    billingSay("支払い方法を選択してください。");
    mountPaypal();
  } catch (e) {
    billingSay(`決済情報を取得できませんでした: ${e.message}`);
  }
}

async function billingGranted(result) {
  state.billing = { ...(state.billing || {}), credits: result.credits };
  $("#billingCredits").textContent = String(result.credits);
  $("#usageChip").textContent = usageText();
  billingSay(result.message, true);
}

function mountPaypal() {
  const info = state.billing;
  const box = $("#paypalButtons");
  if (!info || !info.paypal_client_id || box.dataset.mounted) return;
  const boot = () => {
    if (!window.paypal || !window.paypal.Buttons || box.dataset.mounted) return;
    box.dataset.mounted = "1";
    window.paypal.Buttons({
      style: { layout: "horizontal", height: 38, tagline: false },
      createOrder: (_d, actions) => actions.order.create({
        purchase_units: [{
          description: "Kurage URL2GPTResearcher 調査1回",
          amount: { currency_code: "JPY", value: String(info.price_jpy) },
        }],
      }),
      onApprove: (_d, actions) => actions.order.capture().then(async (order) => {
        try {
          const result = await api("/billing/paypal", {
            method: "POST", body: JSON.stringify({ order_id: order.id }),
          });
          if (result.ok) await billingGranted(result);
          else billingSay(result.message || "決済を確認できませんでした。");
        } catch (e) { billingSay(`決済確認に失敗しました: ${e.message}`); }
      }),
      onError: () => billingSay("PayPal決済でエラーが発生しました。時間をおいて再試行してください。"),
    }).render("#paypalButtons");
  };
  if (window.paypal) { boot(); return; }
  const script = document.createElement("script");
  script.src = `https://www.paypal.com/sdk/js?client-id=${encodeURIComponent(info.paypal_client_id)}&currency=JPY`;
  script.onload = boot;
  script.onerror = () => billingSay("PayPalの決済画面を読み込めませんでした。");
  document.head.appendChild(script);
}

async function verifyUrlai() {
  const wallet = $("#billingWallet").value.trim();
  if (!wallet) { billingSay("送金元ウォレットを入力してください。"); return; }
  const button = $("#verifyUrlai");
  billingSay("Baseチェーン上の送金を確認しています…");
  button.disabled = true;
  try {
    const result = await api("/billing/urlai", {
      method: "POST", body: JSON.stringify({ wallet }),
    });
    if (result.ok) await billingGranted(result);
    else billingSay(result.message || "送金を確認できませんでした。");
  } catch (e) {
    billingSay(`送金確認に失敗しました: ${e.message}`);
  } finally {
    button.disabled = false;
  }
}

/** 1アカウント1サイトが既定。上限に達したら追加ボタンを止めて理由を出す。 */
function renderSiteLimit() {
  const u = state.usage;
  const button = $("#newSite");
  const note = $("#siteLimitNote");
  if (!u || u.sites_limit === null || u.sites_limit === undefined) {
    button.disabled = false;
    note.textContent = "";
    return;
  }
  const full = state.sites.length >= u.sites_limit;
  button.disabled = full;
  note.textContent = full
    ? `登録できるサイトは${u.sites_limit}件までです。2件目以降はオプションのため、ご相談ください。`
    : "";
}

function renderSites() {
  renderSiteLimit();
  const box = $("#siteList");
  if (!state.sites.length) {
    box.className = "muted";
    box.textContent = "まだサイトがありません。「サイトを追加」から公開URLを登録してください。";
    return;
  }
  box.className = "";
  box.innerHTML = "";
  state.sites.forEach((site) => {
    const row = document.createElement("div");
    row.className = "site-item" + (state.site && state.site.id === site.id ? " on" : "");
    const grow = document.createElement("div");
    grow.className = "grow";
    const name = document.createElement("b");
    name.textContent = site.name || site.url;
    const url = document.createElement("div");
    url.className = "u";
    url.textContent = site.url;
    grow.append(name, url);
    if (site.summary) {
      const sum = document.createElement("div");
      sum.className = "u";
      sum.textContent = site.summary;
      grow.append(sum);
    }
    const open = document.createElement("button");
    open.className = "btn small ghost";
    open.textContent = "開く";
    open.onclick = () => selectSite(site.id);
    row.append(grow, open);
    box.append(row);
  });
}

function renderDetail() {
  const site = state.site;
  $("#detailCard").hidden = !site;
  if (!site) return;
  $("#detailName").textContent = site.name || site.url;
  $("#detailUrl").textContent = site.url;
  $("#overviewText").textContent = site.summary || "まだ作成されていません。";
  const topics = $("#topicList");
  topics.innerHTML = "";
  (parseTopics(site.topics)).forEach((t) => {
    const chip = document.createElement("span");
    chip.textContent = t;
    topics.append(chip);
  });
  $("#runResearch").disabled = !site.summary;
  $("#researchNote").textContent = site.summary
    ? "調査には数分かかります。完了すると下の一覧に記事が追加されます。"
    : "先にサイト概要を作成してください。概要が調査の土台になります。";
  $("#installSnippet").textContent = installSnippet(site);
}

function parseTopics(value) {
  // DBにはカンマ区切りで入っている（JSONではない）
  if (Array.isArray(value)) return value;
  return String(value || "").split(",").map((t) => t.trim()).filter(Boolean);
}

function installSnippet(site) {
  const base = window.KURL2GR_PUBLIC_BASE || location.origin;
  return [
    "<?php",
    "// sns.php / sns_widget.php と同じ場所に置きます。",
    `define('KURL2GR_API_BASE', '${base}');`,
    `define('KURL2GR_SITE_KEY', '${site.site_key}');`,
    `define('KURL2GR_SITE_NAME', '${(site.name || "").replace(/'/g, "\\'")}');`,
    "define('KURL2GR_SNS_URL', 'sns.php');",
  ].join("\n");
}

/* --- 記事を読む（顧客サイトに sns.php が無くても中身を確認できる）-------- */

/** 本文中のURLだけをリンクにする。textContentで組むのでHTMLは混ざらない。 */
function renderBody(text) {
  const box = $("#readerBody");
  box.innerHTML = "";
  const pattern = /https?:\/\/[^\s、。）」]+/g;
  let last = 0;
  for (const match of String(text || "").matchAll(pattern)) {
    if (match.index > last) box.append(text.slice(last, match.index));
    const link = document.createElement("a");
    link.href = match[0];
    link.target = "_blank";
    link.rel = "noopener noreferrer";
    link.textContent = match[0];
    box.append(link);
    last = match.index + match[0].length;
  }
  box.append(text.slice(last));
}

function openReader(post) {
  $("#readerTitle").textContent = post.title || "(無題)";
  const by = post.source === "research"
    ? `AIリサーチ${post.model ? " · " + post.model : ""}` : "手動投稿";
  const parts = [fmtDate(post.created_at), by, post.published ? "掲載中" : "非掲載"];
  if (post.keyword) parts.push(`キーワード: ${post.keyword}`);
  $("#readerMeta").textContent = parts.join(" · ");
  renderBody(post.content || "");
  $("#readerCopy").textContent = "本文をコピー";
  $("#readerCopy").onclick = async () => {
    try {
      await navigator.clipboard.writeText(post.content || "");
      $("#readerCopy").textContent = "コピーしました";
    } catch (_) { $("#readerCopy").textContent = "コピーできませんでした"; }
  };
  $("#readerDialog").showModal();
}

function renderPosts() {
  const box = $("#postList");
  if (!state.posts.length) {
    box.className = "muted";
    box.textContent = "まだ投稿がありません。";
    return;
  }
  box.className = "";
  box.innerHTML = "";
  state.posts.forEach((post) => {
    const row = document.createElement("div");
    row.className = "post-item";
    const grow = document.createElement("div");
    grow.className = "grow";
    const title = document.createElement("b");
    title.textContent = post.title || "(無題)";
    const meta = document.createElement("div");
    meta.className = "m";
    const by = post.source === "research"
      ? `AIリサーチ${post.model ? " · " + post.model : ""}` : "手動投稿";
    meta.textContent = `${fmtDate(post.created_at)} · ${by} · ${post.views || 0} view`;
    const excerpt = document.createElement("div");
    excerpt.className = "excerpt";
    excerpt.textContent = post.content || "";
    grow.append(title, meta, excerpt);
    // 見出しと抜粋のどちらからでも全文を開けるようにする
    title.style.cursor = "pointer";
    excerpt.style.cursor = "pointer";
    title.onclick = () => openReader(post);
    excerpt.onclick = () => openReader(post);

    const read = document.createElement("button");
    read.className = "btn small ghost";
    read.textContent = "読む";
    read.onclick = () => openReader(post);

    const tag = document.createElement("span");
    tag.className = "tag " + (post.source === "research" ? "res" : "");
    tag.textContent = post.source === "research" ? "リサーチ" : "手動";

    const toggle = document.createElement("button");
    toggle.className = "btn small ghost";
    toggle.textContent = post.published ? "掲載中" : "非掲載";
    if (post.published) toggle.classList.add("pub");
    toggle.onclick = () => busy(toggle, "更新中", async () => {
      const next = !post.published;
      await api(`/api/posts/${post.id}/publish?published=${next}`, { method: "POST" });
      post.published = next ? 1 : 0;
      toast(next ? "掲載しました" : "掲載を外しました");
      renderPosts();
    }).catch((e) => toast(e.message, true));

    row.append(grow, tag, read, toggle);
    box.append(row);
  });
}

/** 調査は数分かかる。ジョブが片付くまで進捗を出しながら待つ。 */
async function waitForJob(jobId) {
  const note = $("#researchNote");
  const started = Date.now();
  for (;;) {
    const mins = Math.floor((Date.now() - started) / 60000);
    const secs = Math.floor((Date.now() - started) / 1000) % 60;
    note.className = "note";
    note.textContent = `調査中… ${mins}分${String(secs).padStart(2, "0")}秒経過（数分かかります。この画面は閉じても続きます）`;
    await new Promise((r) => setTimeout(r, 5000));
    let job;
    try { job = await api(`/api/research/${jobId}`); }
    catch (e) { note.className = "note bad"; note.textContent = e.message; return; }
    if (job.status === "done") {
      await refreshBilling().catch(() => {});
      toast(`記事を掲載しました（出典 ${job.sources} 件）`);
      note.textContent = `完了: ${job.query || ""}`;
      await refresh();
      return;
    }
    if (job.status === "failed") {
      await refreshBilling().catch(() => {});
      note.className = "note bad";
      note.textContent = `調査に失敗しました: ${job.detail}`;
      toast("調査に失敗しました", true);
      return;
    }
  }
}

async function selectSite(siteId) {
  state.site = state.sites.find((s) => s.id === siteId) || null;
  renderSites();
  renderDetail();
  if (!state.site) return;
  try {
    state.posts = await api(`/api/sites/${siteId}/posts`);
    renderPosts();
    // 調査中に画面を開き直した場合は、進捗表示に戻す
    const { job } = await api(`/api/sites/${siteId}/research`);
    if (job) waitForJob(job.id);
  } catch (e) { toast(e.message, true); }
}

async function refresh(keepSelection = true) {
  const selected = keepSelection && state.site ? state.site.id : null;
  const [sites, usage] = await Promise.all([api("/api/sites"), api("/api/usage")]);
  state.sites = sites;
  state.usage = usage;
  try { await refreshBilling(); } catch (e) { console.warn("課金情報を取得できません", e); }
  $("#usageChip").textContent = usageText();
  renderSites();
  // 既定は1サイトなので、1件だけならそのまま開く（毎回「開く」を押させない）
  const target = selected || (state.sites.length === 1 ? state.sites[0].id : null);
  if (target) await selectSite(target);
}

async function setupActAs() {
  if (!window.KURL2GR_IS_ADMIN || !window.KURL2GR_API_PREFIX) return;
  const box = $("#actAsBox");
  const select = $("#actAsSelect");
  box.hidden = false;
  select.innerHTML = "";
  const self = document.createElement("option");
  self.value = "";
  self.textContent = "自分";
  select.append(self);
  try {
    const { users } = await api("/api/admin/users");
    (users || []).forEach((user) => {
      const option = document.createElement("option");
      option.value = user;
      option.textContent = user;
      select.append(option);
    });
  } catch (error) {
    console.warn("代理対象の一覧を取得できません", error);
  }
  select.value = window.KURL2GR_ACT_AS || "";
  select.onchange = () => {
    const url = new URL(location.href);
    if (select.value) url.searchParams.set("as", select.value);
    else url.searchParams.delete("as");
    location.href = url.toString();
  };
}

function bind() {
  $("#newSite").onclick = async () => {
    const url = prompt("対象サイトの公開URL（https://...）");
    if (!url) return;
    try {
      const site = await api("/api/sites", {
        method: "POST",
        body: JSON.stringify({ url: url.trim(), name: "" }),
      });
      toast("サイトを追加しました");
      await refresh(false);
      await selectSite(site.id);
    } catch (e) { toast(e.message, true); }
  };

  $("#buildOverview").onclick = (event) => busy(event.target, "解析中", async () => {
    try {
      await api(`/api/sites/${state.site.id}/overview`, { method: "POST" });
      toast("概要を作成しました");
      await refresh();
      renderDetail();
    } catch (e) { toast(e.message, true); }
  });

  $("#runResearch").onclick = (event) => busy(event.target, "調査中", async () => {
    try {
      const job = await api(`/api/sites/${state.site.id}/research`, {
        method: "POST",
        body: JSON.stringify({ keyword: $("#keyword").value.trim(), publish: true }),
      });
      $("#keyword").value = "";
      await refreshBilling();
      await waitForJob(job.id);
    } catch (e) {
      if (e.status === 402) {
        toast("調査クレジットがありません", true);
        openBilling();
        return;
      }
      toast(e.message, true);
    }
  });

  $("#readerClose").onclick = () => $("#readerDialog").close();
  $("#buyBtn").onclick = openBilling;
  $("#billingClose").onclick = () => $("#billingDialog").close();
  $("#verifyUrlai").onclick = verifyUrlai;

  $("#addPost").onclick = (event) => busy(event.target, "送信中", async () => {
    const content = $("#postContent").value.trim();
    if (!content) { toast("本文を入力してください", true); return; }
    try {
      await api(`/api/sites/${state.site.id}/posts`, {
        method: "POST",
        body: JSON.stringify({ title: $("#postTitle").value.trim(), content }),
      });
      $("#postTitle").value = "";
      $("#postContent").value = "";
      toast("投稿しました");
      await selectSite(state.site.id);
    } catch (e) { toast(e.message, true); }
  });
}

(async function main() {
  if (window.KURL2GR_USER) {
    $("#userChip").textContent = "@" + window.KURL2GR_USER;
    $("#userChip").hidden = false;
    $("#logoutLink").hidden = false;
  }
  bind();
  setupActAs();
  try { await refresh(false); }
  catch (e) { toast(e.message, true); $("#siteList").textContent = "読み込めませんでした。"; }
})();
