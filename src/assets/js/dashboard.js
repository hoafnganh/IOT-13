/* Dashboard: 3 the cam bien + bieu do Chart.js + 2 switch LED.
   Du lieu that: poll GET /dashboard moi 3 giay (telemetry ESP8266 qua worker),
   bieu do lay tu GET /dashboard/chart. */

window.hasDashboardPolling = true;

setTimeout(() => {
    if (sessionStorage.getItem("loginsuccess") === "true") {
        window.toast.success("Đăng nhập thành công");
        sessionStorage.removeItem("loginsuccess");
    }
}, 300);

const byId = (id) => document.getElementById(id);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/* ------------------------------ The cam bien ------------------------------
   moi the doc gia tri moi nhat tu data.sensors.<key> cua /dashboard */

const sensorCards = {
    temperature: {value: byId("temperatureValue"), bar: byId("temperatureBar"), time: byId("temperatureTime"), key: "temperature", max: 50, decimals: 1},
    humidity: {value: byId("humidityValue"), bar: byId("humidityBar"), time: byId("humidityTime"), key: "humidity", max: 100, decimals: 0},
    light: {value: byId("lightValue"), bar: byId("lightBar"), time: byId("lightTime"), key: "light", max: 1000, decimals: 0}
};

function updateSensorCards(sensors) {
    Object.values(sensorCards).forEach((card) => {
        if (!card.value) return;
        const last = sensors ? sensors[card.key] : null;
        if (!last || last.value == null) return;

        card.value.textContent = card.decimals ? last.value.toFixed(1) : Math.round(last.value);
        card.bar.style.width = `${Math.min(100, (last.value / card.max) * 100)}%`;
        card.time.textContent = new Date(last.recordedAt).toLocaleTimeString("vi-VN");
    });
}

/* ------------------------------ Pill ESP8266 ------------------------------ */

function updatePill(node) {
    const pill = document.querySelector(".system-online");
    const text = byId("espStatusText");
    if (!pill || !text) return;

    const online = node !== null && node.connectionStatus === "online";
    pill.classList.toggle("is-online", online);
    pill.classList.toggle("is-offline", !online);
    text.textContent = online ? "1/1 ESP8266 Online" : "ESP8266 Offline";
}

/* --------------------- Bieu do (Chart.js tai tu CDN) ---------------------
   series tu /dashboard/chart co dang {temperature:[{t,v}],...} -
   tao chart MOT lan roi moi lan poll chi thay data + update(). */

const chartCanvas = byId("sensorChart");
const chartFilter = byId("sensorFilter");
let chart = null;

/* Anh sang da o don vi lux ngay trong telemetry (mach map A0 ve 0-1000) */
const seriesMeta = [
    {key: "temperature", label: "Nhiệt độ (°C)", color: "#ff4d4f", axis: "yPercent"},
    {key: "humidity", label: "Độ ẩm (%)", color: "#1890ff", axis: "yPercent"},
    {key: "light", label: "Ánh sáng (lux)", color: "#faad14", axis: "yLight"}
];

function drawChart(series) {
    if (!chartCanvas || typeof Chart === "undefined" || !series) return;

    if (!chart) {
        chart = new Chart(chartCanvas, {
            type: "line",
            data: {labels: [], datasets: seriesMeta.map((meta) => ({label: meta.label, data: [], borderColor: meta.color, backgroundColor: meta.color, yAxisID: meta.axis, tension: 0.3, pointRadius: 1.5, borderWidth: 2}))},
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                interaction: {mode: "index", intersect: false},
                scales: {
                    yPercent: {position: "left", min: 0, max: 100, title: {display: true, text: "Nhiệt độ (°C)/Độ ẩm (%)"}},
                    yLight: {position: "right", min: 0, max: 1000, title: {display: true, text: "Ánh sáng (lux)"}, grid: {drawOnChartArea: false}}
                }
            }
        });

        if (chartFilter) {
            chartFilter.addEventListener("change", () => {
                const selected = chartFilter.value;
                seriesMeta.forEach((meta, i) => {
                    chart.setDatasetVisibility(i, selected === "all" || selected === meta.key);
                });
                chart.update();
            });
        }
    }

    chart.data.labels = (series[seriesMeta[0].key] || []).map((p) => new Date(p.t).toLocaleTimeString("vi-VN"));
    chart.data.datasets.forEach((dataset, i) => {
        dataset.data = (series[seriesMeta[i].key] || []).map((p) => p.v * (seriesMeta[i].scale || 1));
    });
    chart.update();
}

/* --------------------------- 2 switch LED that ---------------------------
   Bam switch -> POST /devices/{id}/actions (ghi actions PENDING + publish
   len MQTT) -> cho ESP8266 doi trang thai roi bao lai qua telemetry;
   FE hoi trang thai thiet bi moi giay, toi da 10 giay (quá = TIMEOUT). */

const deviceCards = [];

document.querySelectorAll(".device-card").forEach((card) => {
    const switchButton = card.querySelector('[data-action="toggle-device"]');
    const stateElement = card.querySelector(".device-bottom strong");
    if (!switchButton) return;

    const name = card.querySelector("h3").textContent;
    const setOn = (on) => {
        stateElement.textContent = on ? "ON" : "OFF";
        switchButton.classList.toggle("active-switch", on);
        switchButton.setAttribute("aria-pressed", String(on));
    };

    deviceCards.push({id: card.dataset.deviceId, setOn, busy: () => switchButton.disabled});

    switchButton.onclick = async () => {
        if (switchButton.disabled) return;
        const on = !switchButton.classList.contains("active-switch");

        stateElement.textContent = "PENDING";
        switchButton.disabled = true;

        try {
            await window.api.controlDevice(card.dataset.deviceId, on ? "ON" : "OFF");
        } catch (error) {
            window.toast.error(error.message);
            switchButton.disabled = false;
            return;
        }

        for (let i = 0; i < 10; i++) {
            await sleep(1000);
            try {
                const result = await window.api.getDevice(card.dataset.deviceId);
                if (result.data.currentState === (on ? "ON" : "OFF")) {
                    setOn(on);
                    switchButton.disabled = false;
                    window.toast.info((on ? "Đã bật " : "Đã tắt ") + name);
                    return;
                }
            } catch (error) {
                break;
            }
        }
        switchButton.disabled = false;
        window.toast.error("Không nhận được phản hồi từ ESP8266 (TIMEOUT)");
    };
});

/* Poll chinh: dong bo the cam bien, pill, trang thai LED tu server */
function updateDeviceCards(devices) {
    devices.forEach((device) => {
        const card = deviceCards.find((item) => item.id === device.deviceCode);
        if (card && !card.busy()) card.setOn(device.currentState === "ON");
    });
}

async function loadDashboard() {
    try {
        const result = await window.api.getDashboard();
        updateSensorCards(result.data.sensors);
        updatePill(result.data.node);
        updateDeviceCards(result.data.devices);
    } catch (error) {
        updatePill(null);
    }
}

async function loadChart() {
    try {
        const result = await window.api.getChart(30);
        drawChart(result.data.series);
    } catch (error) {
        /* chua co du lieu thi giu bieu do trang */
    }
}

loadDashboard();
loadChart();
setInterval(loadDashboard, 3000);
setInterval(loadChart, 10000);
