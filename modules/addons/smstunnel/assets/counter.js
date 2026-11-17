/*
 * SMSTunnel for WHMCS: the SMS part counter and the merge-field buttons of the text editor.
 * The same rules as lib/Text/SmsCounter.php (GSM 7-bit: 160 in one SMS, 153 per part; Unicode: 70 and
 * 67; ^ { } \ [ ] ~ | € take two places in GSM 7-bit, emoji two in Unicode).
 *
 * (c) 2026 NARBOWEB SRL, MIT License.
 */
(function (root) {
  'use strict';

  var BASIC = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?' +
    '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
  var EXTENDED = '\f^{}\\[~]|€';

  function count(text) {
    var chars = Array.from(String(text));
    var nonGsm = [];
    chars.forEach(function (ch) {
      if (BASIC.indexOf(ch) === -1 && EXTENDED.indexOf(ch) === -1 && nonGsm.indexOf(ch) === -1) {
        nonGsm.push(ch);
      }
    });
    var gsm = nonGsm.length === 0;
    var weights = chars.map(function (ch) {
      if (gsm) {
        return EXTENDED.indexOf(ch) === -1 ? 1 : 2;
      }
      return ch.length; // UTF-16 code units: 2 for emoji outside the Basic Multilingual Plane
    });
    var units = weights.reduce(function (sum, w) { return sum + w; }, 0);
    var single = gsm ? 160 : 70;
    var part = gsm ? 153 : 67;
    var parts;
    if (units === 0) {
      parts = 0;
    } else if (units <= single) {
      parts = 1;
    } else {
      parts = 1;
      var used = 0;
      weights.forEach(function (w) {
        if (used + w > part) {
          parts += 1;
          used = w;
        } else {
          used += w;
        }
      });
    }
    return {
      encoding: gsm ? 'GSM-7' : 'Unicode',
      characters: chars.length,
      units: units,
      parts: parts,
      perPart: parts > 1 ? part : single,
      nonGsm: nonGsm
    };
  }

  // Fills {field} and {$field} with the example values, tidied like lib/Text/Template.php.
  function render(template, samples) {
    var text = String(template).replace(/\r\n?/g, '\n').replace(/\{\$?([A-Za-z][A-Za-z0-9_]*)\}/g, function (match, name) {
      var key = name.toLowerCase();
      return Object.prototype.hasOwnProperty.call(samples, key) ? String(samples[key]) : match;
    });
    text = text.split('\n').map(function (line) {
      return line.replace(/[ \t]+/g, ' ').replace(/ +([,.;:!?])/g, '$1').replace(/\.{4,}/g, '...').trim();
    }).join('\n');
    return text.replace(/\n{3,}/g, '\n\n').trim();
  }

  function fieldsIn(template) {
    var found = [];
    String(template).replace(/\{\$?([A-Za-z][A-Za-z0-9_]*)\}/g, function (match, name) {
      var key = name.toLowerCase();
      if (found.indexOf(key) === -1) {
        found.push(key);
      }
      return match;
    });
    return found;
  }

  function describe(text, known) {
    var c = count(text);
    if (c.parts === 0) {
      return 'Empty: nothing is sent.';
    }
    var line = c.parts + ' SMS · ' + c.units + ' characters · ' + (c.encoding === 'GSM-7' ? 'GSM 7-bit' : 'Unicode') +
      ' (' + c.perPart + (c.parts > 1 ? ' per part' : ' per SMS') + ')';
    if (c.nonGsm.length) {
      line += ' · Unicode because of: ' + c.nonGsm.slice(0, 10).join(' ');
    }
    return line;
  }

  var api = { count: count, render: render, fieldsIn: fieldsIn, describe: describe };
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
    return;
  }
  root.SmsTunnelCounter = api;

  function parse(json, fallback) {
    try {
      var value = JSON.parse(json || '');
      return value === null ? fallback : value;
    } catch (e) {
      return fallback;
    }
  }

  function update(area) {
    var samples = parse(area.getAttribute('data-samples'), {});
    var known = parse(area.getAttribute('data-fields'), []);
    var target = document.getElementById(area.getAttribute('data-counter'));
    if (!target) {
      return;
    }
    var line = describe(render(area.value, samples), known);
    if (known.length) {
      var unknown = fieldsIn(area.value).filter(function (f) { return known.indexOf(f) === -1; });
      if (unknown.length) {
        line += ' · Not a field of this text: {' + unknown.join('}, {') + '}';
      }
      line += ' (with example values)';
    }
    target.textContent = line;
    target.className = target.className.replace(/\s*smstunnel-long/g, '') + (count(render(area.value, samples)).parts > 1 ? ' smstunnel-long' : '');
  }

  function insertAt(area, value) {
    var start = area.selectionStart || 0;
    var end = area.selectionEnd || 0;
    area.value = area.value.slice(0, start) + value + area.value.slice(end);
    area.selectionStart = area.selectionEnd = start + value.length;
    area.focus();
    update(area);
  }

  function ready() {
    var areas = document.querySelectorAll('.smstunnel-admin textarea[data-smstunnel-text]');
    Array.prototype.forEach.call(areas, function (area) {
      area.addEventListener('input', function () { update(area); });
      update(area);
    });
    Array.prototype.forEach.call(document.querySelectorAll('.smstunnel-admin [data-insert]'), function (button) {
      button.addEventListener('click', function () {
        var area = document.getElementById(button.getAttribute('data-target'));
        if (area) {
          insertAt(area, button.getAttribute('data-insert'));
        }
      });
    });
    Array.prototype.forEach.call(document.querySelectorAll('.smstunnel-admin [data-reset]'), function (button) {
      button.addEventListener('click', function () {
        var area = document.getElementById(button.getAttribute('data-reset'));
        if (area) {
          area.value = area.getAttribute('data-default') || '';
          update(area);
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
  } else {
    ready();
  }
})(this);
