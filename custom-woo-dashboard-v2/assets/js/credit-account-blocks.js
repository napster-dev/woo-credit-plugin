(function () {
	if (!window.wc || !window.wc.wcBlocksRegistry || !window.wp || !window.wp.element) {
		return;
	}

	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { createElement: el } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities || { decodeEntities: function (s) { return s; } };
	const { __ } = window.wp.i18n || { __: function (s) { return s; } };

	const settings = window.wcSettings ? window.wcSettings.getSetting('cwd_v2_credit_account_data', {}) : {};

	const defaultTitle = __('Pay on Credit Account', 'custom-woo-dashboard');
	const defaultDesc = __('Your order will be charged to your trade credit account and settled according to your payment terms.', 'custom-woo-dashboard');

	const title = decodeEntities(settings.title || defaultTitle);
	const description = decodeEntities(settings.description || defaultDesc);

	const Content = function () {
		const availableFormatted = settings.formatted_available || '£0.00';
		const limitFormatted = settings.formatted_limit || '£0.00';
		const balanceFormatted = settings.formatted_balance || '£0.00';

		return el(
			'div',
			{
				className: 'cwd-credit-checkout-blocks-box',
				style: {
					marginTop: '10px',
					marginBottom: '12px',
					background: '#ffffff',
					border: '1px solid #e2e8f0',
					borderRadius: '6px',
					padding: '14px 16px'
				}
			},
			description ? el('p', { style: { margin: '0 0 12px 0', color: '#475569', fontSize: '13.5px', lineHeight: '1.45' } }, description) : null,
			el(
				'div',
				{
					style: {
						display: 'grid',
						gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))',
						gap: '10px',
						marginBottom: '6px'
					}
				},
				el(
					'div',
					{ style: { background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: '6px', padding: '10px 12px' } },
					el('span', { style: { display: 'block', fontSize: '11px', fontWeight: 600, textTransform: 'uppercase', color: '#64748b' } }, __('Credit Limit', 'custom-woo-dashboard')),
					el('span', { style: { display: 'block', fontSize: '16px', fontWeight: 700, color: '#0f172a', marginTop: '4px' } }, limitFormatted)
				),
				el(
					'div',
					{ style: { background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: '6px', padding: '10px 12px' } },
					el('span', { style: { display: 'block', fontSize: '11px', fontWeight: 600, textTransform: 'uppercase', color: '#64748b' } }, __('Current Balance', 'custom-woo-dashboard')),
					el('span', { style: { display: 'block', fontSize: '16px', fontWeight: 700, color: '#dc2626', marginTop: '4px' } }, balanceFormatted)
				),
				el(
					'div',
					{ style: { background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: '6px', padding: '10px 12px' } },
					el('span', { style: { display: 'block', fontSize: '11px', fontWeight: 600, textTransform: 'uppercase', color: '#64748b' } }, __('Available Credit', 'custom-woo-dashboard')),
					el('span', { style: { display: 'block', fontSize: '16px', fontWeight: 700, color: '#16a34a', marginTop: '4px' } }, availableFormatted)
				)
			)
		);
	};

	const Label = function () {
		return el('span', null, title);
	};

	registerPaymentMethod({
		name: 'cwd_v2_credit_account',
		label: el(Label, null),
		content: el(Content, null),
		edit: el(Content, null),
		canMakePayment: function () {
			return !!settings.has_credit_role;
		},
		ariaLabel: title,
		supports: {
			features: settings.supports || ['products']
		}
	});
})();
