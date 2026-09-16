async (page) => {
    function assert(value, message) { if (!value) { throw new Error(message); } }
    const table = page.locator('[data-jfb-rules-readonly]');
    assert(await table.locator('tbody tr').count() === 3, 'Stored rules rendered');
    assert(await table.getByText('Show only when', {exact:true}).count() === 2, 'Saved actions displayed');
    assert(await table.locator('input, select, textarea, button').count() === 0, 'Rules have no editing controls');
    assert(await page.locator('[name*="[conditions]"]').count() === 0, 'Rules are not submitted by the editor');
    assert(await page.getByRole('button', {name:'Add rule', exact:true}).count() === 0, 'No add action');
    assert(await page.getByRole('button', {name:'Remove rule', exact:true}).count() === 0, 'No remove action');
    await page.locator('#jfb-name').fill('Updated contact');
    assert(await page.locator('#jfb-name').inputValue() === 'Updated contact', 'Other integration fields remain editable');
    return {passed:['saved rules displayed', 'actions displayed', 'no rule controls', 'no rule POST fields', 'no add/remove', 'other settings editable']};
}
