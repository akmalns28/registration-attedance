import ApexCharts from "apexcharts";

function barChart(rows, metrics, stacked = false) {
    return {
        chart: { type: "bar", height: Math.max(250, rows.length * 35 + 80), stacked, toolbar: { show: false } },
        series: metrics.map(([key, name]) => ({ name, data: rows.map((row) => row[key]) })),
        colors: ["#206bc4", "#9aa7b9"],
        plotOptions: { bar: { horizontal: true } },
        dataLabels: { enabled: false },
        xaxis: { categories: rows.map((row) => row.label), tooltip: { enabled: false } },
        yaxis: { labels: { maxWidth: 160 } },
        tooltip: { x: { formatter: (label) => String(label)
            .replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;") } },
    };
}

export function initializeDashboardCharts(root = document) {
    const charts = JSON.parse(root.querySelector("[data-dashboard-chart-data]").textContent);
    const options = {
        registrations: barChart(charts.registrations, [["count", "Registrasi Aktif"]]),
        attendance: barChart(charts.attendance, [["present", "Hadir"], ["absent", "Belum Hadir"]], true),
        participants: {
            chart: { type: "donut", height: 250 },
            series: charts.participants,
            labels: ["Internal", "External"],
            colors: ["#206bc4", "#2fb344"],
            legend: { position: "bottom" },
        },
    };

    return Promise.all(Array.from(root.querySelectorAll("[data-dashboard-chart]"), (element) => {
        return new ApexCharts(element, options[element.dataset.dashboardChart]).render();
    }));
}
