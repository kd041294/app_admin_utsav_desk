const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
function element(dataset = {}) {
    return { dataset, events: {}, classes: new Set(), attributes: {},
        classList: { toggle(name, on) { if (on) this.owner.classes.add(name); else this.owner.classes.delete(name); } },
        addEventListener(name, callback) { this.events[name] = callback; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; }
    };
}
const keys = ['general','subscription','security','email','notifications','features','system'];
const sections = keys.map(key => element({ settingSection: key }));
const links = keys.map(key => element({ settingTarget: key }));
const ids = Object.fromEntries(['settingsForm','settingsDirty','saveAllSettings','smtpTestForm','refreshSystem'].map(id => [id, element()]));
[...sections,...links,...Object.values(ids)].forEach(item => item.classList.owner = item);
ids.settingsForm.elements = [];
const window = element();
const location = { hash:'#security', pathname:'/admin_utsav_desk/settings/', reload(){this.reloaded=true;} };
const document = { getElementById(id){return ids[id];}, querySelectorAll(selector){return selector==='[data-setting-section]'?sections:links;} };
vm.runInNewContext(fs.readFileSync('assets/js/settings.js','utf8'),{window,location,document});
assert(sections[2].classes.has('active'));
assert.equal(links[2].attributes['aria-current'],'page');
for (const key of keys) { location.hash='#'+key;window.events.hashchange();assert(sections.find(section=>section.classes.has('active')).dataset.settingSection===key); }
location.hash='#maintenance';window.events.hashchange();assert(sections[0].classes.has('active'));
ids.settingsForm.events.input();assert.match(ids.settingsDirty.textContent,/unsaved/);
let prevented=false;ids.smtpTestForm.events.submit({preventDefault(){prevented=true;}});assert(prevented);
prevented=false;window.events.beforeunload({preventDefault(){prevented=true;}});assert(prevented);
ids.settingsForm.events.reset({preventDefault(){}});assert.equal(location.href,location.pathname+location.hash);
const field={willValidate:true,validity:{valid:false},closest(){return sections[3];},focus(){this.focused=true;},reportValidity(){this.reported=true;}};
ids.settingsForm.elements=[field];prevented=false;ids.settingsForm.events.submit({preventDefault(){prevented=true;}});
assert(prevented && field.focused && field.reported && sections[3].classes.has('active'));
field.validity.valid=true;ids.settingsForm.events.submit({preventDefault(){throw new Error('Valid form blocked');}});
assert.equal(ids.saveAllSettings.disabled,true);assert.equal(ids.saveAllSettings.textContent,'Saving…');
ids.refreshSystem.events.click({preventDefault(){}});assert(location.reloaded);
console.log('PASS: section navigation, removed-section fallback, dirty state, reset, SMTP guard, hidden-field validation, submit state and refresh');
