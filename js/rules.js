/* Webmail Exchange – inbox rules (vanilla JS, no build step) */
(function () {
	'use strict'

	const IMPORTANCE = [['High', 'haute'], ['Normal', 'normale'], ['Low', 'basse']]

	let core = null

	/**
	 * The rules of an account, in its settings. prefill: a new rule to
	 * start from (made from a message: its sender, its subject).
	 */
	function open(c, account, prefill) {
		core = c
		const h = core.h
		const el = core.detailEl
		core.setView('detail')
		core.clear(el)
		const wrap = h('div', { class: 'wm-settings wm-rules', dataset: { account: account.email } },
			h('div', { class: 'wm-compose-title' },
				h('button', { type: 'button', class: 'wm-back', 'aria-label': 'Retour', onclick: () => core.openAccountSettings() }, '←'),
				h('h2', {}, 'Règles de ' + account.email)),
			h('p', { class: 'wm-muted' }, 'Exchange applique ces règles, dans l\'ordre, à chaque message qui arrive dans la boîte de réception, '
				+ 'même quand le webmail est fermé. Ce sont les mêmes règles que dans Outlook.'),
			h('div', { class: 'wm-center' }, h('span', { class: 'icon-loading' })))
		el.appendChild(wrap)
		load(account, wrap, prefill)
	}

	async function load(account, wrap, prefill) {
		try {
			const [data, folders] = await Promise.all([
				core.api('/accounts/' + account.id + '/rules'),
				core.api('/accounts/' + account.id + '/folders'),
			])
			const ctx = { account, wrap, rules: data.rules, folders: ordered(folders.folders) }
			if (prefill) edit(ctx, prefill)
			else render(ctx)
		} catch (e) {
			wrap.lastChild.replaceWith(core.h('div', { class: 'wm-form-error' }, e.message))
		}
	}

	/** Folders as a tree: each one after its parent, with its depth. */
	function ordered(folders) {
		const byParent = {}
		folders.forEach((f) => { (byParent[f.parentId || ''] = byParent[f.parentId || ''] || []).push(f) })
		const out = []
		const walk = (parent, depth) => (byParent[parent] || []).forEach((f) => { out.push(Object.assign({ depth }, f)); walk(f.id, depth + 1) })
		walk('', 0)
		return out
	}

	function folderName(ctx, id) {
		const f = ctx.folders.find((x) => x.id === id || x.role === id)
		return f ? f.name : 'un dossier supprimé'
	}

	const quoted = (list) => list.map((s) => '« ' + s + ' »').join(' ou ')

	/** "Si … → …" as Outlook shows it. */
	function summary(ctx, r) {
		const c = r.conditions || {}
		const a = r.actions || {}
		const ifs = []
		if (c.from && c.from.length) ifs.push('de ' + c.from.join(' ou '))
		if (c.senderContains && c.senderContains.length) ifs.push('l\'expéditeur contient ' + quoted(c.senderContains))
		if (c.subject && c.subject.length) ifs.push('l\'objet contient ' + quoted(c.subject))
		if (c.words && c.words.length) ifs.push('l\'objet ou le texte contient ' + quoted(c.words))
		if (c.sentTo && c.sentTo.length) ifs.push('envoyé à ' + c.sentTo.join(' ou '))
		if (c.sentOnlyToMe) ifs.push('envoyé à moi seul')
		if (c.hasAttachments) ifs.push('avec pièce jointe')
		if (c.importance) ifs.push('d\'importance ' + (IMPORTANCE.find(([v]) => v === c.importance) || [0, c.importance])[1])
		const dos = []
		if (a.moveTo) dos.push('déplacer vers ' + folderName(ctx, a.moveTo))
		if (a.copyTo) dos.push('copier vers ' + folderName(ctx, a.copyTo))
		if (a.delete) dos.push('mettre à la corbeille')
		if (a.markRead) dos.push('marquer comme lu')
		if (a.markImportance) dos.push('importance ' + (IMPORTANCE.find(([v]) => v === a.markImportance) || [0, a.markImportance])[1])
		if (a.categories && a.categories.length) dos.push('catégorie ' + a.categories.join(', '))
		if (a.forwardTo && a.forwardTo.length) dos.push('transférer à ' + a.forwardTo.join(', '))
		let text = (ifs.length ? 'Si ' + ifs.join(' et ') : r.editable ? 'Pour tous les messages' : 'Selon des conditions réglées dans Outlook') + ' → ' + (dos.join(', ') || '…')
		if (a.stop) text += ', puis ne pas appliquer les règles suivantes'
		return text
	}

	function render(ctx) {
		const h = core.h
		const { wrap, rules } = ctx
		while (wrap.children.length > 2) wrap.lastChild.remove()
		const list = h('ol', { class: 'wm-rule-list' })
		rules.forEach((r, i) => {
			const toggle = h('input', {
				type: 'checkbox',
				class: 'wm-rule-enabled',
				checked: r.enabled,
				'aria-label': 'Activer la règle ' + r.name,
				onchange: () => call(ctx, '/enable', { ruleId: r.id, enabled: toggle.checked }, toggle.checked ? 'Règle activée' : 'Règle désactivée'),
			})
			list.appendChild(h('li', { class: 'wm-rule' + (r.enabled ? '' : ' off'), dataset: { id: r.id } },
				toggle,
				h('div', { class: 'wm-rule-text' },
					h('strong', { class: 'wm-rule-name' }, r.name),
					!r.editable ? h('span', { class: 'wm-rule-tag', title: 'Elle utilise des conditions ou des actions que seul Outlook sait modifier' }, 'créée dans Outlook') : null,
					r.inError ? h('span', { class: 'wm-rule-tag wm-rule-tag-error' }, 'en erreur') : null,
					h('div', { class: 'wm-muted wm-rule-summary' }, summary(ctx, r))),
				h('div', { class: 'wm-rule-buttons' },
					h('button', {
						type: 'button', class: 'wm-rule-up', 'aria-label': 'Monter', title: 'Appliquer plus tôt', disabled: i === 0,
						onclick: () => call(ctx, '/move', { ruleId: r.id, priority: r.priority - 1 }, null),
					}, '↑'),
					h('button', {
						type: 'button', class: 'wm-rule-down', 'aria-label': 'Descendre', title: 'Appliquer plus tard', disabled: i === rules.length - 1,
						onclick: () => call(ctx, '/move', { ruleId: r.id, priority: r.priority + 1 }, null),
					}, '↓'),
					r.editable ? h('button', { type: 'button', class: 'wm-rule-edit', onclick: () => edit(ctx, r) }, 'Modifier') : null,
					h('button', {
						type: 'button',
						class: 'wm-rule-delete wm-danger',
						onclick: () => { if (confirm('Supprimer la règle « ' + r.name + ' » ?')) call(ctx, '/delete', { ruleId: r.id }, 'Règle supprimée') },
					}, 'Supprimer'))))
		})
		wrap.appendChild(rules.length ? list : h('p', { class: 'wm-empty' }, 'Aucune règle pour l\'instant'))
		wrap.appendChild(h('div', { class: 'wm-compose-actions' },
			h('button', { type: 'button', class: 'primary wm-rule-new', onclick: () => edit(ctx, null) }, '＋ Nouvelle règle')))
	}

	async function call(ctx, path, body, okMessage) {
		try {
			const res = await core.api('/accounts/' + ctx.account.id + '/rules' + path, { method: 'POST', body })
			ctx.rules = res.rules
			if (okMessage) core.toast(okMessage)
			render(ctx)
			return true
		} catch (e) {
			core.toast(e.message, true)
			render(ctx) // back to what Exchange has
			return false
		}
	}

	/** The form of a rule; r: an existing rule, a prefill (no id) or null. */
	function edit(ctx, r) {
		const h = core.h
		const { wrap } = ctx
		while (wrap.children.length > 2) wrap.lastChild.remove()
		const c = (r && r.conditions) || {}
		const a = (r && r.actions) || {}
		const join = (v) => (v || []).join(', ')
		const split = (v) => v.split(/[,;\n]/).map((s) => s.trim()).filter(Boolean)
		const text = (name, value, placeholder) => {
			const i = h('input', { type: 'text', name, placeholder })
			i.value = value
			return i
		}
		const check = (name, checked) => h('input', { type: 'checkbox', name, checked: !!checked })
		const importance = (name, value, none) => h('select', { name },
			h('option', { value: '' }, none), IMPORTANCE.map(([v, l]) => h('option', { value: v, selected: value === v }, l)))
		const folderSelect = (name, value, first) => h('select', { name },
			first.map(([v, l]) => h('option', { value: v, selected: value === v }, l)),
			ctx.folders.map((f) => h('option', { value: f.id, selected: value === f.id }, '  '.repeat(f.depth) + f.name)))

		const f = {
			name: text('ruleName', (r && r.name) || '', 'Par exemple : Factures fournisseurs'),
			from: text('ruleFrom', join(c.from), 'adresse@exemple.fr'),
			senderContains: text('ruleSenderContains', join(c.senderContains), 'mots séparés par des virgules'),
			subject: text('ruleSubject', join(c.subject), 'mots séparés par des virgules'),
			words: text('ruleWords', join(c.words), 'mots séparés par des virgules'),
			sentTo: text('ruleSentTo', join(c.sentTo), 'adresse@exemple.fr'),
			sentOnlyToMe: check('ruleSentOnlyToMe', c.sentOnlyToMe),
			hasAttachments: check('ruleHasAttachments', c.hasAttachments),
			importance: importance('ruleImportance', c.importance, 'peu importe'),
			move: folderSelect('ruleMove', a.delete ? '__delete' : (a.moveTo || ''), [['', 'Ne pas déplacer'], ['__delete', 'Mettre à la corbeille']]),
			copyTo: folderSelect('ruleCopyTo', a.copyTo || '', [['', 'Ne pas copier']]),
			markRead: check('ruleMarkRead', a.markRead),
			markImportance: importance('ruleMarkImportance', a.markImportance, 'ne pas changer'),
			categories: text('ruleCategories', join(a.categories), 'Par exemple : Comptabilité'),
			forwardTo: text('ruleForwardTo', join(a.forwardTo), 'adresse@exemple.fr'),
			stop: check('ruleStop', a.stop),
			enabled: check('ruleEnabled', !r || !r.id || r.enabled),
		}
		const field = (label, input) => h('label', { class: 'wm-field' }, h('span', {}, label), input)
		const box = (input, label) => h('label', { class: 'wm-radio' }, input, ' ' + label)
		const err = h('div', { class: 'wm-form-error', role: 'alert' })
		const save = h('button', { type: 'submit', class: 'primary' }, 'Enregistrer')
		const isNew = !r || !r.id

		wrap.appendChild(h('form', {
			class: 'wm-rule-form',
			onsubmit: async (e) => {
				e.preventDefault()
				err.textContent = ''
				save.disabled = true
				try {
					const res = await core.api('/accounts/' + ctx.account.id + '/rules', {
						method: 'POST',
						body: {
							ruleId: isNew ? '' : r.id,
							name: f.name.value.trim(),
							enabled: f.enabled.checked,
							conditions: {
								from: split(f.from.value),
								senderContains: split(f.senderContains.value),
								subject: split(f.subject.value),
								words: split(f.words.value),
								sentTo: split(f.sentTo.value),
								sentOnlyToMe: f.sentOnlyToMe.checked,
								hasAttachments: f.hasAttachments.checked,
								importance: f.importance.value,
							},
							actions: {
								moveTo: f.move.value === '__delete' ? '' : f.move.value,
								delete: f.move.value === '__delete',
								copyTo: f.copyTo.value,
								markRead: f.markRead.checked,
								markImportance: f.markImportance.value,
								categories: split(f.categories.value),
								forwardTo: split(f.forwardTo.value),
								stop: f.stop.checked,
							},
						},
					})
					ctx.rules = res.rules
					core.toast(isNew ? 'Règle créée' : 'Règle enregistrée')
					render(ctx)
				} catch (ex) {
					err.textContent = ex.message
					save.disabled = false
				}
			},
		},
		h('h3', {}, isNew ? 'Nouvelle règle' : 'Modifier la règle'),
		field('Nom', f.name),
		h('h4', {}, 'Quand un message arrive et que…'),
		h('p', { class: 'wm-muted' }, 'Toutes les conditions remplies doivent être vraies ; pour une condition à plusieurs mots ou adresses, un seul suffit. Sans condition, la règle s\'applique à tous les messages.'),
		field('Il vient de', f.from),
		field('L\'expéditeur contient', f.senderContains),
		field('L\'objet contient', f.subject),
		field('L\'objet ou le texte contient', f.words),
		field('Il est envoyé à', f.sentTo),
		field('Son importance est', f.importance),
		h('div', { class: 'wm-rule-checks' }, box(f.hasAttachments, 'Il a une pièce jointe'), box(f.sentOnlyToMe, 'Il m\'est envoyé à moi seul')),
		h('h4', {}, 'Alors…'),
		field('Le déplacer', f.move),
		field('En copier un exemplaire', f.copyTo),
		field('Changer l\'importance', f.markImportance),
		field('Catégorie', f.categories),
		field('Le transférer à', f.forwardTo),
		h('div', { class: 'wm-rule-checks' },
			box(f.markRead, 'Le marquer comme lu'),
			box(f.stop, 'Ne pas appliquer les règles suivantes'),
			box(f.enabled, 'Règle activée')),
		err,
		h('div', { class: 'wm-compose-actions' }, save, h('button', { type: 'button', onclick: () => render(ctx) }, 'Annuler'))))
		f.name.focus()
	}

	window.WebmailRules = { open }
})()
