'use strict';

(function () {
  var isNode = typeof require === 'function' && typeof module !== 'undefined';
  var source = isNode
    ? require('node:fs').readFileSync(require('node:path').resolve(__dirname, '../assets/crm/crm.js'), 'utf8')
    : readFile('assets/crm/crm.js');

  function assert(condition, message) {
    if (!condition) throw new Error(message);
  }

  function assertEqual(actual, expected, message) {
    if (actual !== expected) {
      throw new Error(message + ' (expected ' + String(expected) + ', got ' + String(actual) + ')');
    }
  }

  function extractFunction(marker) {
    var markerAt = source.indexOf(marker);
    assert(markerAt !== -1, 'missing source marker: ' + marker);
    var functionAt = source.indexOf('function', markerAt);
    var braceAt = source.indexOf('{', functionAt);
    assert(functionAt > markerAt && braceAt > functionAt, 'invalid function boundary: ' + marker);

    var depth = 0;
    var quote = '';
    var escaped = false;
    var lineComment = false;
    var blockComment = false;
    for (var index = braceAt; index < source.length; index += 1) {
      var char = source[index];
      var next = source[index + 1] || '';
      if (lineComment) {
        if (char === '\n') lineComment = false;
        continue;
      }
      if (blockComment) {
        if (char === '*' && next === '/') {
          blockComment = false;
          index += 1;
        }
        continue;
      }
      if (quote) {
        if (escaped) escaped = false;
        else if (char === '\\') escaped = true;
        else if (char === quote) quote = '';
        continue;
      }
      if (char === '/' && next === '/') {
        lineComment = true;
        index += 1;
        continue;
      }
      if (char === '/' && next === '*') {
        blockComment = true;
        index += 1;
        continue;
      }
      if (char === '"' || char === "'" || char === '`') {
        quote = char;
        continue;
      }
      if (char === '{') depth += 1;
      if (char === '}') {
        depth -= 1;
        if (depth === 0) return source.slice(functionAt, index + 1);
      }
    }
    throw new Error('unterminated function: ' + marker);
  }

  var state = { user: { id: 7 } };
  var buildPreviewItems = eval('(' + extractFunction('buildWizardMailPreviewItems: function (draft, plan)') + ')');

  function normalizePromotionChannel(channel) {
    var value = String(channel || '').trim().toLowerCase();
    return { mail: 'email', edm: 'email', 'e-mail': 'email', '邮件': 'email' }[value] || value;
  }

  function makeContext() {
    return {
      data: { mail_accounts: [] },
      normalizePromotionChannel: normalizePromotionChannel,
      isEmailPromotionChannel: function (channel) {
        return normalizePromotionChannel(channel) === 'email';
      },
      buildExecutionPlan: function () {
        throw new Error('the runtime contract must pass an explicit execution plan');
      }
    };
  }

  function build(draft, items) {
    return buildPreviewItems.call(makeContext(), draft, {
      items: items || [],
      mailItems: [],
      duplicateMailItems: []
    });
  }

  var combinedDraft = {
    channel_key: 'whatsapp',
    campaign_type: 'whatsapp',
    mail_subject: 'Hello {contact_name}',
    mail_body_html: '<p>Mail body</p>',
    mail_account_ids: []
  };

  var personalized = build(combinedDraft, [{
    customer_id: 10,
    customer_name: 'Runtime Customer',
    contact_id: 20,
    contact_name: 'Runtime Contact',
    variable_contact_name: 'Runtime Contact',
    target_level: 'contact',
    email: 'runtime@example.com',
    channel: 'whatsapp'
  }]);
  assertEqual(personalized.length, 1, 'combined task should keep a personalized preview');
  assertEqual(personalized[0].customer_name, 'Runtime Customer', 'combined task should use the real preview customer');
  assertEqual(personalized[0]._preview_only, true, 'combined task preview must not pretend to be queued');

  var combinedWithoutTargets = build(combinedDraft, []);
  assertEqual(combinedWithoutTargets.length, 1, 'combined task without targets should still be previewable');
  assertEqual(combinedWithoutTargets[0]._sample_preview, true, 'combined task without targets should use a sample');

  var nonMailDraft = {
    channel_key: 'whatsapp',
    campaign_type: 'whatsapp',
    mail_subject: '',
    mail_body_html: '',
    mail_account_ids: []
  };
  assertEqual(build(nonMailDraft, []).length, 0, 'non-mail task without mail content should remain non-mail');

  var emailDraft = {
    channel_key: 'email',
    campaign_type: 'email',
    mail_subject: '',
    mail_body_html: '',
    mail_account_ids: []
  };
  assertEqual(build(emailDraft, [])[0]._sample_preview, true, 'email task should keep the empty-content sample preview');

  if (typeof print === 'function') print('CRM marketing mail preview runtime contract: OK');
  else console.log('CRM marketing mail preview runtime contract: OK');
})();
