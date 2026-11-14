/* Webmail Exchange – « Envoyer par e-mail » in the Files app (vanilla JS, no build step) */
(function () {
	'use strict'

	const _ = (s, vars) => (window.t ? window.t('webmail', s, vars, undefined, { escape: false }) : s)
	// an envelope (inline SVG, as the Files app wants its icons)
	const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2m0 4-8 5-8-5V6l8 5 8-5z"/></svg>'
	const READ = 1
	/** at most this many files in one message */
	const MAX = 20

	const isFile = (node) => node && node.type === 'file' && ((node.permissions === undefined) || (node.permissions & READ))

	/** A new message of the webmail (another tab), the files attached. */
	function compose(nodes) {
		const params = new URLSearchParams()
		nodes.slice(0, MAX).forEach((n) => params.append('file', n.path))
		window.open(window.OC.generateUrl('/apps/webmail/') + '?' + params.toString(), '_blank', 'noopener')
		return null
	}

	const action = {
		id: 'webmail-send-by-mail',
		displayName: () => _('Envoyer par e-mail'),
		title: () => _('Un nouveau message du webmail, avec ce fichier en pièce jointe'),
		iconSvgInline: () => ICON,
		enabled: (nodes) => Array.isArray(nodes) && nodes.length > 0 && nodes.length <= MAX && nodes.every(isFile),
		exec: async (node) => compose([node]),
		execBatch: async (nodes) => { compose(nodes); return nodes.map(() => null) },
		order: 60,
	}

	// the list the Files app reads its actions from (@nextcloud/files)
	const actions = window._nc_fileactions = window._nc_fileactions || []
	if (!actions.some((a) => a && a.id === action.id)) actions.push(action)
})()
