(function () {
	'use strict';

	var DATA = (window.LVAASMembers && window.LVAASMembers.rows) || [];
	var COLUMNS = ['last', 'first', 'email', 'phone', 'status'];

	var state = { sortKey: 'last', sortDir: 1, filter: '' };

	var tbody     = document.getElementById('lvaas-members-tbody');
	var search    = document.getElementById('lvaas-members-search');
	var exportBtn = document.getElementById('lvaas-members-export');
	var countEl   = document.getElementById('lvaas-members-count');

	if (!tbody) {
		return;
	}

	function esc(s) {
		s = (s === null || s === undefined) ? '' : String(s);
		return s.replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function compareRows(a, b) {
		var av = (a[state.sortKey] || '').toString().toLowerCase();
		var bv = (b[state.sortKey] || '').toString().toLowerCase();
		if (av < bv) { return -state.sortDir; }
		if (av > bv) { return  state.sortDir; }
		return 0;
	}

	function visibleRows() {
		var f = state.filter.trim().toLowerCase();
		var filtered;
		if (f === '') {
			filtered = DATA.slice();
		} else {
			filtered = DATA.filter(function (r) {
				for (var i = 0; i < COLUMNS.length; i++) {
					var v = r[COLUMNS[i]];
					if (v && v.toString().toLowerCase().indexOf(f) !== -1) {
						return true;
					}
				}
				return false;
			});
		}
		filtered.sort(compareRows);
		return filtered;
	}

	function render() {
		var rows = visibleRows();
		if (rows.length === 0) {
			tbody.innerHTML = '<tr><td colspan="' + COLUMNS.length + '" style="text-align:center;padding:1em;">' +
				esc((window.LVAASMembers && window.LVAASMembers.i18n.noMatches) || 'No members match.') + '</td></tr>';
		} else {
			var html = '';
			for (var i = 0; i < rows.length; i++) {
				var r = rows[i];
				html += '<tr>' +
					'<td>' + esc(r.last)   + '</td>' +
					'<td>' + esc(r.first)  + '</td>' +
					'<td>' + esc(r.email)  + '</td>' +
					'<td>' + esc(r.phone)  + '</td>' +
					'<td>' + esc(r.status) + '</td>' +
				'</tr>';
			}
			tbody.innerHTML = html;
		}
		if (countEl) {
			countEl.textContent = rows.length + ' / ' + DATA.length;
		}
	}

	function csvCell(v) {
		var s = (v === null || v === undefined) ? '' : String(v);
		if (s.indexOf(',') !== -1 || s.indexOf('"') !== -1 || s.indexOf('\n') !== -1 || s.indexOf('\r') !== -1) {
			return '"' + s.replace(/"/g, '""') + '"';
		}
		return s;
	}

	function downloadCSV() {
		var rows = visibleRows();
		var lines = [COLUMNS.join(',')];
		for (var i = 0; i < rows.length; i++) {
			var r = rows[i];
			var cells = [];
			for (var j = 0; j < COLUMNS.length; j++) {
				cells.push(csvCell(r[COLUMNS[j]]));
			}
			lines.push(cells.join(','));
		}
		var csv  = lines.join('\r\n');
		var blob = new Blob([new Uint8Array([0xEF, 0xBB, 0xBF]), csv], { type: 'text/csv;charset=utf-8' });
		var url  = URL.createObjectURL(blob);
		var a    = document.createElement('a');
		a.href = url;
		a.download = 'lvaas-members-' + new Date().toISOString().slice(0, 10) + '.csv';
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
		URL.revokeObjectURL(url);
	}

	function applySortIndicators() {
		var ths = document.querySelectorAll('th[data-key]');
		for (var i = 0; i < ths.length; i++) {
			ths[i].classList.remove('sorted-asc', 'sorted-desc');
			if (ths[i].getAttribute('data-key') === state.sortKey) {
				ths[i].classList.add(state.sortDir === 1 ? 'sorted-asc' : 'sorted-desc');
			}
		}
	}

	if (search) {
		search.addEventListener('input', function (e) {
			state.filter = e.target.value;
			render();
		});
	}

	if (exportBtn) {
		exportBtn.addEventListener('click', downloadCSV);
	}

	var headers = document.querySelectorAll('th[data-key]');
	for (var i = 0; i < headers.length; i++) {
		(function (th) {
			th.addEventListener('click', function () {
				var key = th.getAttribute('data-key');
				if (state.sortKey === key) {
					state.sortDir = -state.sortDir;
				} else {
					state.sortKey = key;
					state.sortDir = 1;
				}
				applySortIndicators();
				render();
			});
		})(headers[i]);
	}

	applySortIndicators();
	render();
})();
