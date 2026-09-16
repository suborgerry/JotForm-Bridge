async (page) => {
    function assert(value, message) { if (!value) { throw new Error(message); } }
    const field = path => page.locator('[data-jotform-field="' + path + '"]');
    assert(await field('message').isDisabled(), 'Message starts inactive');
    await page.locator('input[type=radio][value="E-mail"]').check();
    assert(await field('message').isVisible(), 'Email reveals message');
    assert(await field('message').evaluate(e => e.required), 'Visible message remains required');
    await field('message').fill('Preserved until inactive');
    await page.locator('input[type=radio][value="Phone"]').check();
    assert(!(await field('message').isVisible()), 'Phone hides message');
    assert(await field('address.city').evaluate(e => e.required), 'Phone requires city');
    assert(await field('topics_of').first().isVisible(), 'Choice group revealed');
    assert(await field('topics_of').evaluateAll(es => es.every(e => !e.required)), 'Checkboxes never require every option');
    await field('full_name.first').fill('Ada');
    await field('full_name.last').fill('Lovelace');
    await field('email').fill('ada@example.test');
    await field('address.city').fill('London');
    await field('topics_of').first().check();
    await page.evaluate(() => {
        window.jotformBridgeSettings.powBits.contact = 0;
        window.requests = [];
        window.fetch = (url, options) => {
            window.requests.push(JSON.parse(options.body));
            return new Promise(resolve => { window.finish = () => resolve({status:200, json:()=>Promise.resolve({success:true, message:'Accepted'})}); });
        };
        document.querySelector('form').addEventListener('jotformbridge:success', event => {
            window.successFields = event.detail.fields;
            window.cityDuringSuccess = document.querySelector('[data-jotform-field="address.city"]').value;
        });
    });
    await page.getByRole('button', {name:'Submit', exact:true}).click();
    await page.waitForFunction(() => window.requests.length === 1);
    await page.evaluate(() => document.querySelector('form').requestSubmit());
    const result = await page.evaluate(() => ({count:window.requests.length, fields:window.requests[0].fields, buttonDisabled:document.querySelector('button[type=submit]').disabled}));
    assert(result.count === 1 && !result.buttonDisabled, 'Double submit blocked while focusable');
    assert(!Object.hasOwn(result.fields, 'message'), 'Inactive stale value excluded from request');
    assert(result.fields['address.city'] === 'London' && result.fields.topics_of.length === 1, 'Active composite and checkbox serialized');
    await page.evaluate(() => window.finish());
    await page.waitForFunction(() => !document.querySelector('form').hasAttribute('data-jotform-busy'));
    assert(await page.evaluate(() => window.cityDuringSuccess === 'London'), 'Success event precedes reset');
    assert(await field('message').isDisabled(), 'Success reset restores initial visibility');
    assert(!(await field('address.city').evaluate(e => e.required)), 'Reset removes conditional requirement');
    await page.evaluate(() => document.body.appendChild(document.querySelector('form').cloneNode(true)));
    await page.waitForFunction(() => document.querySelectorAll('form').length === 2 && document.querySelectorAll('form')[1].querySelector('[data-jotform-field="message"]').disabled);
    await page.locator('form').nth(1).locator('input[type=radio][value="E-mail"]').check();
    assert(await page.locator('form').nth(1).locator('[data-jotform-field="message"]').isEnabled(), 'Cloned conditional form reactivates its fields');
    return {passed: ['initial state', 'show/hide', 'conditional required', 'checkbox group', 'composite serialization', 'hidden values omitted', 'double submit', 'success before reset', 'reset state', 'dynamic form']};
}
