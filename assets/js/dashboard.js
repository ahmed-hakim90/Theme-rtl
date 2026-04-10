/**
 * WooKapso Dashboard — Chart.js + AJAX
 */
(function ($) {
	'use strict';

	var lineChart = null;
	var barChart = null;

	function fmtNum(n) {
		if (typeof n !== 'number') {
			n = parseInt(n, 10);
		}
		return isNaN(n) ? '0' : String(n);
	}

	function badgeClass(statusKey) {
		var k = (statusKey || '').toLowerCase();
		if (k === 'cancelled') {
			return 'wkpd-badge wkpd-badge--cancelled';
		}
		if (k === 'completed') {
			return 'wkpd-badge wkpd-badge--completed';
		}
		if (k === 'processing' || k === 'on-hold') {
			return 'wkpd-badge wkpd-badge--processing';
		}
		return 'wkpd-badge';
	}

	function destroyCharts() {
		if (lineChart) {
			lineChart.destroy();
			lineChart = null;
		}
		if (barChart) {
			barChart.destroy();
			barChart = null;
		}
	}

	function chartFont() {
		return '"Inter", "Cairo", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
	}

	function chartCommonOptions() {
		return {
			responsive: true,
			maintainAspectRatio: false,
			interaction: {
				mode: 'index',
				intersect: false
			},
			plugins: {
				legend: {
					labels: {
						font: { family: chartFont(), size: 12, weight: '500' },
						color: '#697386',
						usePointStyle: true,
						boxWidth: 8,
						boxHeight: 8,
						padding: 16
					}
				},
				tooltip: {
					backgroundColor: 'rgba(10, 37, 64, 0.92)',
					titleFont: { family: chartFont(), size: 12 },
					bodyFont: { family: chartFont(), size: 13 },
					padding: 10,
					cornerRadius: 6,
					displayColors: true
				}
			},
			scales: {
				x: {
					grid: {
						display: false,
						drawBorder: false
					},
					ticks: {
						font: { family: chartFont(), size: 11 },
						color: '#697386',
						maxRotation: 0
					}
				},
				y: {
					beginAtZero: true,
					grid: {
						color: '#ebeef1',
						drawBorder: false
					},
					ticks: {
						font: { family: chartFont(), size: 11 },
						color: '#697386',
						precision: 0
					}
				}
			}
		};
	}

	function renderCharts(d) {
		if (typeof Chart === 'undefined') {
			return;
		}

		var labels = d.chart_labels && d.chart_labels.length ? d.chart_labels : (d.orders_by_date || []).map(function (x) {
			return x.date;
		});

		var lineData = (d.orders_by_date || []).map(function (x) {
			return x.count;
		});

		var confirmed = d.confirmed_series || [];
		var cancelled = d.cancelled_series || [];

		var lineEl = document.getElementById('wkpd-chart-line');
		var barEl = document.getElementById('wkpd-chart-bar');
		if (!lineEl || !barEl) {
			return;
		}

		destroyCharts();

		var lineOpts = chartCommonOptions();
		lineOpts.plugins.legend = { display: false };
		lineOpts.scales.y.ticks.precision = 0;

		lineChart = new Chart(lineEl.getContext('2d'), {
			type: 'line',
			data: {
				labels: labels,
				datasets: [{
					label: wookapsoDashboard.i18n.ordersLine,
					data: lineData,
					borderColor: '#635bff',
					backgroundColor: 'rgba(99, 91, 255, 0.06)',
					fill: true,
					tension: 0.4,
					borderWidth: 2,
					pointRadius: 3,
					pointHoverRadius: 5,
					pointBackgroundColor: '#ffffff',
					pointBorderColor: '#635bff',
					pointBorderWidth: 2
				}]
			},
			options: lineOpts
		});

		var barOpts = chartCommonOptions();
		barOpts.plugins.legend.position = 'bottom';
		barOpts.plugins.legend.rtl = true;
		barOpts.scales.x.stacked = false;
		barOpts.scales.y.stacked = false;

		barChart = new Chart(barEl.getContext('2d'), {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [
					{
						label: wookapsoDashboard.i18n.confirmed,
						data: confirmed,
						backgroundColor: 'rgba(48, 196, 141, 0.85)',
						borderRadius: 5,
						borderSkipped: false,
						maxBarThickness: 22
					},
					{
						label: wookapsoDashboard.i18n.cancelled,
						data: cancelled,
						backgroundColor: 'rgba(223, 28, 65, 0.75)',
						borderRadius: 5,
						borderSkipped: false,
						maxBarThickness: 22
					}
				]
			},
			options: barOpts
		});
	}

	function fillTable(rows) {
		var $body = $('#wkpd-recent-body');
		$body.empty();

		if (!rows || !rows.length) {
			$body.append(
				$('<tr/>').append(
					$('<td/>', { colspan: 5, text: '—' })
				)
			);
			return;
		}

		rows.forEach(function (r) {
			var tr = $('<tr/>');
			tr.append(
				$('<td/>').append(
					$('<a/>', {
						href: r.edit_url,
						text: '#' + r.id
					})
				)
			);
			tr.append($('<td/>').text(r.customer));
			tr.append(
				$('<td/>').append(
					$('<span/>', {
						class: badgeClass(r.status_key),
						text: r.status
					})
				)
			);
			tr.append($('<td/>').text(r.tracking));
			tr.append($('<td/>').text(r.date));
			$body.append(tr);
		});
	}

	function applyPayload(d) {
		var days = d.days || 7;
		var tpl = wookapsoDashboard.i18n.lastNDays || '';
		var periodText = tpl.indexOf('%d') !== -1
			? tpl.replace('%d', String(days))
			: (wookapsoDashboard.i18n.last7Days || '');
		$('#wkpd-period').text(periodText).removeAttr('hidden');

		$('#wkpd-kpi-total').text(fmtNum(d.total_orders));
		$('#wkpd-kpi-confirmed').text(fmtNum(d.confirmed_orders));
		$('#wkpd-kpi-cancelled').text(fmtNum(d.cancelled_orders));
		$('#wkpd-kpi-shipped').text(fmtNum(d.shipped_orders));

		var cr = typeof d.confirmation_rate === 'number' ? d.confirmation_rate : parseFloat(d.confirmation_rate);
		var canr = typeof d.cancellation_rate === 'number' ? d.cancellation_rate : parseFloat(d.cancellation_rate);
		$('#wkpd-rate-confirm').text((isNaN(cr) ? 0 : cr) + '%');
		$('#wkpd-rate-cancel').text((isNaN(canr) ? 0 : canr) + '%');

		var $alert = $('#wkpd-alert-cancel');
		if (!isNaN(canr) && canr > 30) {
			$alert.removeAttr('hidden');
			$('#wkpd-alert-cancel-text').text(wookapsoDashboard.i18n.warnCancel);
		} else {
			$alert.attr('hidden', true);
		}

		fillTable(d.recent_orders);
		renderCharts(d);
	}

	function loadData(refresh) {
		$('#wkpd-load-error').attr('hidden', true);
		$('#wkpd-loading').show();
		$('#wkpd-content').attr('hidden', true);

		$.post(
			wookapsoDashboard.ajaxUrl,
			{
				action: 'wookapso_dashboard_data',
				nonce: wookapsoDashboard.nonce,
				refresh: refresh ? 1 : 0,
				days: 7
			}
		)
			.done(function (res) {
				if (!res || !res.success || !res.data) {
					$('#wkpd-load-error-text').text(wookapsoDashboard.i18n.loadError);
					$('#wkpd-load-error').removeAttr('hidden');
					$('#wkpd-loading').hide();
					return;
				}
				$('#wkpd-load-error').attr('hidden', true);
				applyPayload(res.data);
				$('#wkpd-loading').hide();
				$('#wkpd-content').removeAttr('hidden');
			})
			.fail(function () {
				$('#wkpd-load-error-text').text(wookapsoDashboard.i18n.loadError);
				$('#wkpd-load-error').removeAttr('hidden');
				$('#wkpd-loading').hide();
			});
	}

	$(function () {
		loadData(false);

		$('#wkpd-refresh').on('click', function () {
			loadData(true);
		});
	});
})(jQuery);
