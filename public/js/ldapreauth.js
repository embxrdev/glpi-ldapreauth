/*
 * LDAP Re-auth on Approval — GLPI plugin
 * Copyright (C) 2026 Robin Embacher (embxr) — GPLv2+
 *
 * Inserts Windows login/password fields below the comment field in the
 * timeline approval/rejection (TicketValidation answer) form. GLPI 11's timeline is
 * Twig-rendered and injected on demand, so we anchor on the comment
 * <textarea> and watch the DOM with a MutationObserver.
 *
 * Field names MUST match the server side (hook.php):
 *   _ldapreauth_login  /  _ldapreauth_password
 */
(function () {

   if (window.LdapReauthInit) {
      return;
   }
   window.LdapReauthInit = true;

   // Field labels per language, keyed by the <html> lang attribute
   // (full code first, then 2-letter prefix). Falls back to English.
   var LABELS = {
      en: ["Windows username", "Windows password"],
      cs: ["Uživatelské jméno Windows", "Heslo Windows"],
      de: ["Windows-Benutzername", "Windows-Passwort"],
      es: ["Nombre de usuario de Windows", "Contraseña de Windows"],
      fr: ["Nom d'utilisateur Windows", "Mot de passe Windows"],
      it: ["Nome utente Windows", "Password di Windows"],
      ja: ["Windows ユーザー名", "Windows パスワード"],
      nl: ["Windows-gebruikersnaam", "Windows-wachtwoord"],
      pl: ["Nazwa użytkownika Windows", "Hasło Windows"],
      pt: ["Nome de usuário do Windows", "Senha do Windows"],
      ru: ["Имя пользователя Windows", "Пароль Windows"],
      tr: ["Windows kullanıcı adı", "Windows parolası"],
      zh: ["Windows 用户名", "Windows 密码"]
   };

   function pickLabels() {
      var lang = (document.documentElement.getAttribute("lang") || "en")
         .toLowerCase().replace("-", "_");
      return LABELS[lang] || LABELS[lang.split("_")[0]] || LABELS.en;
   }

   var LABEL_USER = pickLabels()[0];
   var LABEL_PASS = pickLabels()[1];

   var FIELDS_HTML =
      "<div class='ldapreauth-fields' style='margin-top:12px;margin-bottom:12px;'>" +
        "<div style='margin-bottom:8px;'>" +
          "<label style='display:block;margin-bottom:2px;font-weight:600;'>" + LABEL_USER + "</label>" +
          "<input type='text' name='_ldapreauth_login' autocomplete='off' class='form-control' style='width:100%;'>" +
        "</div>" +
        "<div>" +
          "<label style='display:block;margin-bottom:2px;font-weight:600;'>" + LABEL_PASS + "</label>" +
          "<input type='password' name='_ldapreauth_password' autocomplete='off' class='form-control' style='width:100%;'>" +
        "</div>" +
      "</div>";

   // Button keywords for both decisions, across the plugin's languages.
   var DECISION_WORDS = [
      // approve
      "approve", "genehmig", "approuver", "valider", "aprob", "approv",
      "aprova", "goedkeur", "zatwierd", "schválit", "schvál", "onayla",
      "утверд", "承認", "批准",
      // reject
      "reject", "refus", "ablehn", "rechaz", "rifiut", "recus", "afwijz",
      "weiger", "odrzu", "zamítn", "reddet", "отклон", "却下", "拒否", "拒绝"
   ];

   function isDecisionEl(el) {
      if (!el) return false;
      var t = ((el.textContent || "") + " " +
               (el.value || "") + " " +
               ((el.getAttribute && el.getAttribute("aria-label")) || "") + " " +
               ((el.getAttribute && el.getAttribute("title")) || "")).toLowerCase();
      for (var i = 0; i < DECISION_WORDS.length; i++) {
         if (t.indexOf(DECISION_WORDS[i]) !== -1) return true;
      }
      return false;
   }

   function isApprovalForm(form) {
      var ctrls = form.querySelectorAll('button, input[type="submit"], a.btn, a[role="button"]');
      for (var i = 0; i < ctrls.length; i++) {
         if (isDecisionEl(ctrls[i])) return true;
      }
      return false;
   }

   function commentAnchor(form) {
      return form.querySelector('textarea[name="comment_validation"]')
          || form.querySelector('textarea[name*="comment"]')
          || form.querySelector('textarea');
   }

   function enhance(form) {
      if (form.dataset.ldapreauthDone === "1") return;

      if (form.querySelector('input[name="_ldapreauth_login"]')) {
         form.dataset.ldapreauthDone = "1";
         return;
      }

      var ta = commentAnchor(form);
      if (!ta) {
         return;
      }

      var wrapper = document.createElement('div');
      wrapper.innerHTML = FIELDS_HTML;
      var node = wrapper.firstChild;

      var container = ta.closest('.form-field, .mb-3, .mb-2, .field, .row, .col-12') || ta.parentNode;
      container.parentNode.insertBefore(node, container.nextSibling);

      form.dataset.ldapreauthDone = "1";
   }

   function scan() {
      var forms = document.querySelectorAll("form");
      for (var i = 0; i < forms.length; i++) {
         if (isApprovalForm(forms[i])) {
            enhance(forms[i]);
         }
      }
   }

   document.addEventListener("DOMContentLoaded", scan);

   try {
      var mo = new MutationObserver(function () { scan(); });
      mo.observe(document.documentElement, { childList: true, subtree: true });
   } catch (e) {
      // MutationObserver unavailable; fall back to interval only.
   }

   setInterval(scan, 1500);

})();
