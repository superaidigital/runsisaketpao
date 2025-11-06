<?php
// pages/registration.php
// Registration form page for an event (Fully Upgraded Version + Shipping)

// --- 1. Validate and get Event Code ---
if (!isset($_GET['event_code']) || empty($_GET['event_code'])) {
    header('Location: index.php');
    exit;
}
$event_code = $_GET['event_code'];

// --- 2. Fetch event and distance data ---
// [MODIFIED] Added enable_shipping, shipping_cost, payment_deadline
$event_stmt = $mysqli->prepare("SELECT id, name, is_registration_open, payment_bank, payment_account_name, payment_account_number, payment_qr_code_url, start_date, enable_shipping, shipping_cost, payment_deadline FROM events WHERE event_code = ? LIMIT 1");
$event_stmt->bind_param("s", $event_code);
$event_stmt->execute();
$event_result = $event_stmt->get_result();
if ($event_result->num_rows === 0) {
    header('Location: index.php?page=home&error=event_not_found');
    exit;
}
$event = $event_result->fetch_assoc();
$event_stmt->close();

if (!$event['is_registration_open']) {
    header('Location: index.php?page=microsite&event_code=' . urlencode($event_code));
    exit;
}

$distances_stmt = $mysqli->prepare("SELECT id, name, price, category FROM distances WHERE event_id = ? ORDER BY price DESC");
$distances_stmt->bind_param("i", $event['id']);
$distances_stmt->execute();
$distances_result = $distances_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$distances_stmt->close();

// --- 3. Fetch Master Data for form options ---
$master_titles = $mysqli->query("SELECT * FROM master_titles ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
$master_shirt_sizes = $mysqli->query("SELECT * FROM master_shirt_sizes ORDER BY FIELD(name, 'XS', 'S', 'M', 'L', 'XL', '2XL', '3XL')")->fetch_all(MYSQLI_ASSOC);
$master_genders = $mysqli->query("SELECT * FROM master_genders ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

// --- 4. Check for logged-in runner and fetch their data ---
$logged_in_runner_data = null;
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    // [MODIFIED] Added address to pre-fill shipping
    $user_stmt = $mysqli->prepare("SELECT title, first_name, last_name, gender, birth_date, email, phone, line_id, thai_id, emergency_contact_name, emergency_contact_phone, disease, disease_detail, address FROM users WHERE id = ?");
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $result = $user_stmt->get_result();
    if ($result->num_rows > 0) {
        $logged_in_runner_data = $result->fetch_assoc();
    }
    $user_stmt->close();
}


// Set Page Title
$page_title = 'สมัครเข้าร่วม: ' . e($event['name']);
?>

<div id="multi-step-form-container">
    <h2 class="text-3xl font-extrabold mb-6 text-gray-800">สมัคร: <?= e($event['name']) ?></h2>

    <div id="progress-bar-container" class="mb-8"></div>

    <form id="registration-form" onsubmit="return false;">
        <input type="hidden" name="event_id" value="<?= e($event['id']) ?>">
        <div id="form-step-content">
            <!-- JS will render content here -->
        </div>
    </form>

    <div class="flex justify-between mt-8">
        <button id="prev-btn" class="py-2 px-6 rounded-lg bg-gray-300 text-gray-800 hover:bg-gray-400 transition font-bold" onclick="prevStep()">
            <i class="fa-solid fa-chevron-left mr-2"></i> ย้อนกลับ
        </button>
        <button id="next-btn" class="py-2 px-6 rounded-lg bg-primary text-white hover:opacity-90 transition font-bold" onclick="nextStep()">
            ถัดไป <i class="fa-solid fa-chevron-right ml-2"></i>
        </button>
    </div>
</div>


<script>
// --- JavaScript for handling the multi-step form ---

// --- 1. State Management ---
let currentStep = 1;
const totalSteps = 3;
let registrationData = {};

const currentEvent = <?= json_encode($event, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const distances = <?= json_encode($distances_result, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const eventCode = '<?= e($event_code) ?>';
const loggedInRunner = <?= $logged_in_runner_data ? json_encode($logged_in_runner_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null' ?>;
const masterTitles = <?= json_encode($master_titles, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const masterShirtSizes = <?= json_encode($master_shirt_sizes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const masterGenders = <?= json_encode($master_genders, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// --- 2. Core Rendering and Helper Functions ---

function showMessage(title, text) {
    const box = document.getElementById('message-box');
    if (box) {
        document.getElementById('message-title').textContent = title;
        document.getElementById('message-text').textContent = text;
        document.getElementById('message-text').classList.remove('hidden');
        document.getElementById('message-content-container').classList.add('hidden');
        document.getElementById('message-action-btn').classList.add('hidden');
        box.classList.remove('hidden');
    } else {
        alert(`${title}\n\n${text}`);
    }
}

function renderProgressBar() {
    const registrationSteps = [
        { name: 'เลือกระยะทาง' },
        { name: 'กรอกข้อมูลส่วนตัว' },
        { name: 'สรุปและยืนยัน' }
    ];
    const container = document.getElementById('progress-bar-container');
    if (!container) return;
    container.innerHTML = `
        <div class="flex justify-between text-xs font-medium text-gray-500 mb-2">
            ${registrationSteps.map((s, index) => `<span class="${index + 1 === currentStep ? 'text-primary font-bold' : ''}">${s.name}</span>`).join('')}
        </div>
        <div class="w-full bg-gray-200 rounded-full h-2.5">
            <div class="bg-primary h-2.5 rounded-full transition-all duration-500" style="width: ${((currentStep - 1) / (totalSteps - 1)) * 100}%"></div>
        </div>
    `;
}

// [NEW] Handle shipping option change
function updateShippingOption(radio) {
    const addressContainer = document.getElementById('shipping-address-container');
    const addressTextarea = document.getElementById('shipping_address');
    if (!addressContainer || !addressTextarea) return;

    if (radio.value === 'จัดส่ง') {
        addressContainer.classList.remove('hidden');
        addressTextarea.required = true;
    } else {
        addressContainer.classList.add('hidden');
        addressTextarea.required = false;
    }
}

function handleDiseaseChange(event) {
    const diseaseDetailContainer = document.getElementById('disease-detail-container');
    const diseaseDetailTextarea = document.getElementById('disease_detail');
    if (!diseaseDetailContainer || !diseaseDetailTextarea) return;
    const value = event && event.target ? event.target.value : null;
    if (value === 'มีโรคประจำตัว') {
        diseaseDetailContainer.classList.remove('hidden');
        diseaseDetailTextarea.required = true;
    } else {
        diseaseDetailContainer.classList.add('hidden');
        diseaseDetailTextarea.required = false;
    }
}

function attachEventListeners() {
    if (currentStep === 2) {
        // Attach disease radio listeners
        const diseaseRadios = document.querySelectorAll('input[name="disease"]');
        diseaseRadios.forEach(radio => radio.addEventListener('change', handleDiseaseChange));
        
        // Attach shipping radio listeners
        const shippingRadios = document.querySelectorAll('input[name="shipping_option"]');
        shippingRadios.forEach(radio => radio.addEventListener('change', () => updateShippingOption(radio)));

        // Attach Thai ID validator
        const thaiIdInput = document.getElementById('thai_id');
        const thaiIdError = document.getElementById('thai-id-error');
        if (thaiIdInput && thaiIdError) {
            thaiIdInput.addEventListener('blur', () => {
                thaiIdError.classList.toggle('hidden', !thaiIdInput.value || validateThaiID(thaiIdInput.value));
            });
        }
    }
}

function renderCurrentStep() {
    renderProgressBar();
    const content = document.getElementById('form-step-content');
    const prevBtn = document.getElementById('prev-btn');
    const nextBtn = document.getElementById('next-btn');

    if (!content || !prevBtn || !nextBtn) return;
    content.innerHTML = '';
    prevBtn.style.display = currentStep > 1 ? 'inline-block' : 'none';
    
    // [MODIFIED] Check if pay later is enabled
    const payLaterEnabled = currentEvent.payment_deadline != null;
    
    nextBtn.innerHTML = currentStep === totalSteps
        ? (payLaterEnabled ? `<i class="fa-solid fa-check-circle mr-2"></i> ยืนยันการสมัคร (ยังไม่ชำระเงิน)` : `<i class="fa-solid fa-check-circle mr-2"></i> ยืนยันการสมัคร`)
        : `ถัดไป <i class="fa-solid fa-chevron-right ml-2"></i>`;
    
    nextBtn.disabled = false;

    if (currentStep === 1) {
        content.innerHTML = `
            <div class="space-y-4">
                <h3 class="text-xl font-semibold mb-4">1. เลือกระยะทางการแข่งขัน</h3>
                ${distances.map(d => `
                    <label class="flex items-center p-4 border rounded-lg cursor-pointer transition duration-200 hover:border-primary has-[:checked]:border-primary has-[:checked]:bg-blue-50">
                        <input type="radio" name="distance_id" value="${d.id}" data-distance-name="${e(d.name)}" class="h-5 w-5 text-primary focus:ring-primary" ${registrationData.distance_id == d.id ? 'checked' : ''}>
                        <div class="ml-4 flex justify-between w-full items-center">
                            <span class="font-medium text-gray-900 text-lg">${e(d.name)} (${e(d.category)})</span>
                            <span class="font-bold text-primary text-lg">${parseFloat(d.price).toLocaleString('th-TH')} บาท</span>
                        </div>
                    </label>
                `).join('') || '<p class="text-gray-500">ไม่มีระยะทางให้เลือกสำหรับกิจกรรมนี้</p>'}
            </div>
        `;
    } else if (currentStep === 2) {
        const selectedDistance = distances.find(d => d.id == registrationData.distance_id);
        const userInfo = registrationData.userInfo || loggedInRunner || {};
        const getUserInfoValue = key => userInfo[key] || '';
        const titleOptions = masterTitles.map(t => `<option value="${e(t.name)}" ${userInfo.title === t.name ? 'selected' : ''}>${e(t.name)}</option>`).join('');
        const shirtSizeOptions = masterShirtSizes.map(s => `<option value="${e(s.name)}" ${userInfo.shirt_size === s.name ? 'selected' : ''}>${e(s.name)} ${e(s.description)}</option>`).join('');
        const genderOptions = masterGenders.map(g => `<option value="${e(g.name)}" ${userInfo.gender === g.name ? 'selected' : ''}>${e(g.name)}</option>`).join('');

        // [NEW] Shipping HTML
        let shippingHtml = '';
        if (currentEvent.enable_shipping == 1) {
            const shippingCost = parseFloat(currentEvent.shipping_cost || 0);
            // Check if shipping_option was previously selected, default to 'รับเอง'
            const isShippingSelected = getUserInfoValue('shipping_option') === 'จัดส่ง';
            const isSelfPickupSelected = !isShippingSelected; // Default

            shippingHtml = `
                <div class="border-t pt-4">
                    <h4 class="text-md font-semibold text-gray-800 mb-2">เลือกวิธีรับอุปกรณ์ (Race Kit)</h4>
                    <div class="space-y-3">
                        <label class="flex items-center p-3 border rounded-lg cursor-pointer hover:border-primary has-[:checked]:border-primary has-[:checked]:bg-blue-50">
                            <input type="radio" name="shipping_option" value="รับเอง" class="h-4 w-4 text-primary" ${isSelfPickupSelected ? 'checked' : ''}>
                            <span class="ml-3 font-medium text-gray-900">รับด้วยตนเอง (หน้างาน)</span>
                            <span class="ml-auto font-bold text-primary">ฟรี</span>
                        </label>
                        <label class="flex items-center p-3 border rounded-lg cursor-pointer hover:border-primary has-[:checked]:border-primary has-[:checked]:bg-blue-50">
                            <input type="radio" name="shipping_option" value="จัดส่ง" class="h-4 w-4 text-primary" ${isShippingSelected ? 'checked' : ''}>
                            <span class="ml-3 font-medium text-gray-900">จัดส่งทางไปรษณีย์</span>
                            <span class="ml-auto font-bold text-primary">+${shippingCost.toLocaleString('th-TH')} บาท</span>
                        </label>
                    </div>
                    <div id="shipping-address-container" class="mt-4 ${isShippingSelected ? '' : 'hidden'}">
                        <label for="shipping_address" class="block text-sm font-medium text-gray-700 mb-1">ที่อยู่สำหรับจัดส่ง <span class="text-red-500">*</span></label>
                        <textarea id="shipping_address" name="shipping_address" rows="4" class="w-full p-2 border border-gray-300 rounded-md shadow-sm" placeholder="บ้านเลขที่, ถนน, ตำบล/แขวง, อำเภอ/เขต, จังหวัด, รหัสไปรษณีย์">${e(getUserInfoValue('shipping_address') || getUserInfoValue('address'))}</textarea>
                    </div>
                </div>
            `;
        } else {
             // If shipping is disabled, force 'รับเอง'
             shippingHtml = `<input type="hidden" name="shipping_option" value="รับเอง">`;
        }

        content.innerHTML = `
            <h3 class="text-xl font-semibold mb-4">2. ข้อมูลส่วนตัวและเสื้อ (ระยะทาง: ${e(selectedDistance?.name)})</h3>
            <div class="p-6 bg-white rounded-xl border border-gray-200 space-y-4">
                ${loggedInRunner ? 
                    `<div class="bg-green-50 border-l-4 border-green-500 text-green-800 p-4 rounded-md">
                        <p class="font-bold"><i class="fa fa-check-circle mr-2"></i>เข้าสู่ระบบในชื่อ ${e(getUserInfoValue('first_name'))}</p>
                        <p class="text-sm">ข้อมูลของคุณถูกกรอกไว้ล่วงหน้าแล้ว กรุณาตรวจสอบและกรอกข้อมูลที่เหลือ</p>
                    </div>` : 
                    `<div class="bg-blue-50 border-l-4 border-blue-500 text-blue-800 p-4 rounded-md flex justify-between items-center">
                        <div><p class="font-bold">เคยสมัครกิจกรรมกับเราแล้วใช่ไหม?</p><p class="text-sm">เข้าสู่ระบบเพื่อกรอกข้อมูลอัตโนมัติ</p></div>
                        <a href="index.php?page=dashboard" class="bg-blue-500 text-white font-bold py-2 px-4 rounded-lg hover:bg-blue-600 transition text-sm whitespace-nowrap"><i class="fa-solid fa-sign-in-alt mr-2"></i>เข้าสู่ระบบ</a>
                    </div>`
                }
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div><label for="title" class="block text-sm font-medium text-gray-700 mb-1">คำนำหน้า <span class="text-red-500">*</span></label><select id="title" name="title" required class="w-full p-2 border border-gray-300 rounded-md shadow-sm">${titleOptions}</select></div>
                    <div><label for="first_name" class="block text-sm font-medium text-gray-700 mb-1">ชื่อจริง <span class="text-red-500">*</span></label><input type="text" id="first_name" name="first_name" required class="w-full p-2 border border-gray-300 rounded-md shadow-sm" value="${e(getUserInfoValue('first_name'))}"></div>
                    <div><label for="last_name" class="block text-sm font-medium text-gray-700 mb-1">นามสกุล <span class="text-red-500">*</span></label><input type="text" id="last_name" name="last_name" required class="w-full p-2 border border-gray-300 rounded-md shadow-sm" value="${e(getUserInfoValue('last_name'))}"></div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                     <div><label for="gender" class="block text-sm font-medium text-gray-700 mb-1">เพศ <span class="text-red-500">*</span></label><select id="gender" name="gender" required class="w-full p-2 border border-gray-300 rounded-md shadow-sm"><option value="">-- กรุณาเลือก --</option>${genderOptions}</select></div>
                    <div><label for="birth_date" class="block text-sm font-medium text-gray-700 mb-1">วัน/เดือน/ปีเกิด <span class="text-red-500">*</span></label><input type="date" id="birth_date" name="birth_date" required class="w-full p-2 border border-gray-300 rounded-md shadow-sm" value="${e(getUserInfoValue('birth_date'))}"></div>
                    <div><label for="thai_id" class="block text-sm font-medium text-gray-700 mb-1">หมายเลขบัตรประชาชน <span class="text-red-500">*</span></label><input type="text" id="thai_id" name="thai_id" required pattern="\\d{13}" maxlength="13" class="w-full p-2 border border-gray-300 rounded-md shadow-sm" value="${e(getUserInfoValue('thai_id'))}"><p id="thai-id-error" class="text-xs text-red-500 mt-1 hidden">รูปแบบหมายเลขบัตรประชาชนไม่ถูกต้อง</p></div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div><label for="email" class="block text-sm font-medium text-gray-700 mb-1">อีเมล <span class="text-red-500">*</span></label><input type="email" id="email" name="email" required class="w-full p-2 border border-gray-300 rounded-md shadow-sm" value="${e(getUserInfoValue('email'))}"></div>
                    <div><label for="phone" class="block text-sm font-medium text-gray-700 mb-1">เบอร์โทรศัพท์ <span class="text-red-500">*</span></label><input type="tel" id="phone" name="phone" required class="w-full p-2 border border-gray-300 rounded-md shadow-sm" value="${e(getUserInfoValue('phone'))}"></div>
                </div>
                <div class="border-t pt-4">
                    <h4 class="text-md font-semibold text-gray-800 mb-2">ข้อมูลผู้ติดต่อฉุกเฉิน</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div><label for="emergency_contact_name" class="block text-sm font-medium text-gray-700 mb-1">ชื่อผู้ติดต่อ <span class="text-red-500">*</span></label><input type="text" id="emergency_contact_name" name="emergency_contact_name" value="${e(getUserInfoValue('emergency_contact_name'))}" required class="w-full p-2 border border-gray-300 rounded-md shadow-sm"></div>
                        <div><label for="emergency_contact_phone" class="block text-sm font-medium text-gray-700 mb-1">เบอร์โทรศัพท์ติดต่อ <span class="text-red-500">*</span></label><input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" value="${e(getUserInfoValue('emergency_contact_phone'))}" required class="w-full p-2 border border-gray-300 rounded-md shadow-sm"></div>
                    </div>
                </div>
                <div class="p-4 rounded-md bg-red-50 border border-red-200">
                    <label class="block text-sm font-medium text-red-600 mb-2">ข้อมูลทางการแพทย์ (สำคัญ)</label>
                    <div class="flex items-center space-x-6">
                         <label class="flex items-center"><input type="radio" name="disease" value="ไม่มีโรคประจำตัว" class="form-radio h-4 w-4 text-primary" ${getUserInfoValue('disease') !== 'มีโรคประจำตัว' ? 'checked' : ''}><span class="ml-2 text-gray-700">ไม่มีโรคประจำตัว</span></label>
                        <label class="flex items-center"><input type="radio" name="disease" value="มีโรคประจำตัว" class="form-radio h-4 w-4 text-primary" ${getUserInfoValue('disease') === 'มีโรคประจำตัว' ? 'checked' : ''}><span class="ml-2 text-gray-700">มีโรคประจำตัว</span></label>
                    </div>
                     <div id="disease-detail-container" class="mt-4 ${getUserInfoValue('disease') !== 'มีโรคประจำตัว' ? 'hidden' : ''}">
                         <label for="disease_detail" class="block text-sm font-medium text-gray-700 mb-1">โปรดระบุ:</label>
                         <textarea id="disease_detail" name="disease_detail" rows="3" class="w-full p-2 border border-gray-300 rounded-md shadow-sm" ${getUserInfoValue('disease') === 'มีโรคประจำตัว' ? 'required' : ''}>${e(getUserInfoValue('disease_detail'))}</textarea>
                    </div>
                </div>
                <div><label for="shirt_size" class="block text-sm font-medium text-gray-700 mb-1">ไซส์เสื้อ <span class="text-red-500">*</span></label><select id="shirt_size" name="shirt_size" required class="w-full p-2 border border-gray-300 rounded-md shadow-sm"><option value="">-- กรุณาเลือก --</option>${shirtSizeOptions}</select></div>
                
                <!-- [NEW] Shipping HTML goes here -->
                ${shippingHtml}
            </div>
        `;
    } else if (currentStep === 3) {
        const selectedDistance = distances.find(d => d.id == registrationData.distance_id);
        const userInfo = registrationData.userInfo;
        const getUserInfoValueSummary = (key) => e(userInfo?.[key] || '-');
        
        // [NEW] Calculate Total Price
        const racePrice = parseFloat(selectedDistance?.price || 0);
        const shippingCost = (userInfo.shipping_option === 'จัดส่ง' && currentEvent.enable_shipping == 1) ? parseFloat(currentEvent.shipping_cost || 0) : 0;
        const totalPrice = racePrice + shippingCost;
        registrationData.total_amount = totalPrice; // Store total for submission

        let summaryHtml = `
            <p><strong>ชื่อ-สกุล:</strong> ${getUserInfoValueSummary('title')} ${getUserInfoValueSummary('first_name')} ${getUserInfoValueSummary('last_name')}</p>
            <p><strong>อีเมล:</strong> ${getUserInfoValueSummary('email')}</p>
            <p><strong>โทรศัพท์:</strong> ${getUserInfoValueSummary('phone')}</p>
            <hr class="my-2 border-gray-300">
            <p><strong>ระยะทาง:</strong> ${e(selectedDistance?.name)} (${racePrice.toLocaleString('th-TH')} บาท)</p>
            <p><strong>ขนาดเสื้อ:</strong> ${e(registrationData.shirt_size)}</p>
            <p><strong>การรับอุปกรณ์:</strong> ${getUserInfoValueSummary('shipping_option')} (${shippingCost.toLocaleString('th-TH')} บาท)</p>
        `;
        
        if (userInfo.shipping_option === 'จัดส่ง') {
            summaryHtml += `<p class="pl-4"><strong>ที่อยู่จัดส่ง:</strong> ${getUserInfoValueSummary('shipping_address')}</p>`;
        }
        
        // [MODIFIED] Check if pay later is enabled
        const payLaterEnabled = currentEvent.payment_deadline != null;
        let paymentHtml = '';
        
        if (payLaterEnabled) {
            paymentHtml = `
                <div class="bg-blue-50 border-l-4 border-blue-500 text-blue-800 p-4 rounded-md">
                    <p class="font-bold"><i class="fa-solid fa-clock-rotate-left mr-2"></i>ยืนยันการสมัคร (Pay Later)</p>
                    <p class="text-sm">คุณสามารถยืนยันการสมัครก่อน และกลับมาชำระเงินได้ในภายหลัง</p>
                </div>
            `;
        } else {
             // If pay later is disabled, show payment info
            paymentHtml = `
                <h3 class="text-xl font-semibold my-4"><i class="fa-solid fa-money-check-dollar mr-2"></i> การชำระเงิน</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                    <div class="bg-white p-4 rounded-lg border text-center shadow-sm">
                        <p class="font-semibold text-gray-700 mb-2">สแกน QR Code เพื่อชำระเงิน</p>
                        <img src="${e(currentEvent.payment_qr_code_url) || 'https://placehold.co/160x160?text=QR+Code'}" alt="Payment QR Code" class="mx-auto my-2 rounded-md shadow-sm w-40 h-40 object-contain bg-white">
                    </div>
                    <div class="space-y-4">
                        <div class="bg-white p-4 rounded-lg border shadow-sm">
                            <label for="payment_slip" class="block text-sm font-medium text-gray-700 mb-2">อัปโหลดหลักฐาน <span class="text-red-500">*</span></label>
                            <input type="file" id="payment_slip" name="payment_slip" required accept="image/jpeg,image/png,application/pdf" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-gray-100 hover:file:bg-gray-200 file:cursor-pointer">
                             <p class="text-xs text-gray-500 mt-1">ไฟล์ JPG, PNG, PDF ขนาดไม่เกิน 5MB</p>
                        </div>
                    </div>
                </div>
            `;
        }

        content.innerHTML = `
             <h3 class="text-xl font-semibold mb-4">3. สรุปข้อมูลการสมัคร</h3>
             <div class="p-6 bg-gray-50 rounded-xl border border-gray-200 mb-6 space-y-2 text-gray-800 text-sm">
                ${summaryHtml}
            </div>
            
            ${paymentHtml}
            
            <div class="bg-primary text-white p-4 rounded-lg text-center shadow-lg mt-6">
                <p class="text-lg">ยอดชำระเงินทั้งหมด</p>
                <p class="text-4xl font-extrabold">${totalPrice.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} บาท</p>
            </div>
        `;
    }
    
    // Helper function to escape html in template literals
    function e(str) {
        if (!str) return '';
        return str.toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    attachEventListeners();
}

// --- 3. Navigation and Validation ---

function validateThaiID(id) {
    if (!id || id.length !== 13 || !/^\d+$/.test(id)) return false;
    let sum = 0;
    for (let i = 0; i < 12; i++) {
        sum += parseInt(id.charAt(i)) * (13 - i);
    }
    return parseInt(id.charAt(12)) === (11 - (sum % 11)) % 10;
}

function nextStep() {
    const form = document.getElementById('registration-form');
    if (!form) return;

    if (currentStep === 1) {
        const selectedDistanceRadio = form.querySelector('input[name="distance_id"]:checked');
        if (!selectedDistanceRadio) {
            showMessage('ข้อมูลไม่ครบถ้วน', 'กรุณาเลือกระยะทางการแข่งขัน');
            return;
        }
        registrationData.distance_id = selectedDistanceRadio.value;
        registrationData.distance_name = selectedDistanceRadio.dataset.distanceName;
    } else if (currentStep === 2) {
        const elements = form.elements;
        const requiredFields = ['title', 'first_name', 'last_name', 'gender', 'birth_date', 'thai_id', 'email', 'phone', 'emergency_contact_name', 'emergency_contact_phone', 'shirt_size'];
        
        // [NEW] Add shipping fields to validation if enabled
        if (currentEvent.enable_shipping == 1) {
            // Find the checked shipping_option radio
            const shippingOption = form.querySelector('input[name="shipping_option"]:checked');
            if (!shippingOption) {
                showMessage('ข้อมูลไม่ครบถ้วน', 'กรุณาเลือกวิธีรับอุปกรณ์');
                return;
            }
            if (shippingOption.value === 'จัดส่ง') {
                requiredFields.push('shipping_address');
            }
        }

        for (const fieldName of requiredFields) {
            const el = elements[fieldName];
            if (!el) continue; // Skip if element doesn't exist

            if ((el.type === 'radio' && !form.querySelector(`input[name="${fieldName}"]:checked`)) ||
                (el.type !== 'radio' && !el.value.trim())) {
                
                let labelText = fieldName;
                const label = el.labels?.[0] || document.querySelector(`label[for="${fieldName}"]`);
                if(label) labelText = label.innerText.replace('*','').trim();

                showMessage('ข้อมูลไม่ครบถ้วน', `กรุณากรอกข้อมูล: ${labelText}`);
                el.focus();
                return;
            }
        }
        
        if (elements['disease'].value === 'มีโรคประจำตัว' && !elements['disease_detail'].value.trim()) {
            showMessage('ข้อมูลไม่ครบถ้วน', `กรุณากรอกข้อมูล: โปรดระบุโรคประจำตัว`);
            elements['disease_detail'].focus();
            return;
        }
        if (!validateThaiID(elements.thai_id.value)) {
            showMessage('ข้อมูลไม่ถูกต้อง', 'รูปแบบหมายเลขบัตรประชาชนไม่ถูกต้อง');
            elements.thai_id.focus();
            return;
        }
        const formData = new FormData(form);
        registrationData.userInfo = Object.fromEntries(formData.entries());
        registrationData.shirt_size = elements.shirt_size.value;
    }

    if (currentStep < totalSteps) {
        currentStep++;
        renderCurrentStep();
        window.scrollTo(0, 0);
    } else {
        completeRegistration();
    }
}

function prevStep() {
    if (currentStep > 1) {
        if (currentStep === 2) {
             const form = document.getElementById('registration-form');
             if(form) {
                 const formData = new FormData(form);
                 registrationData.userInfo = Object.fromEntries(formData.entries());
                 registrationData.shirt_size = form.elements.shirt_size?.value;
             }
        }
        currentStep--;
        renderCurrentStep();
        window.scrollTo(0, 0);
    }
}

// --- 4. Final Submission ---
function completeRegistration() {
    // [MODIFIED] Check if pay later is enabled. If not, validate slip.
    const payLaterEnabled = currentEvent.payment_deadline != null;
    let slipFile = null;

    if (!payLaterEnabled) {
        const slipInput = document.getElementById('payment_slip');
        if (!slipInput || !slipInput.files || slipInput.files.length === 0) {
            showMessage('ข้อมูลไม่ครบถ้วน', 'กรุณาอัปโหลดหลักฐานการชำระเงิน');
            return;
        }
        slipFile = slipInput.files[0];
        const allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
        if (!allowedTypes.includes(slipFile.type) || slipFile.size > 5 * 1024 * 1024) {
            showMessage('ไฟล์ไม่ถูกต้อง', 'กรุณาอัปโหลดไฟล์ JPG, PNG, หรือ PDF ขนาดไม่เกิน 5MB');
            return;
        }
    }

    const finalFormData = new FormData();
    finalFormData.append('event_id', currentEvent.id);
    finalFormData.append('event_code', eventCode);
    finalFormData.append('distance_id', registrationData.distance_id);
    finalFormData.append('shirt_size', registrationData.shirt_size);
    finalFormData.append('distance_name', registrationData.distance_name);
    finalFormData.append('total_amount', registrationData.total_amount); // [NEW] Send total
    
    // [NEW] Add shipping option and address
    finalFormData.append('shipping_option', registrationData.userInfo.shipping_option || 'รับเอง');
    if (registrationData.userInfo.shipping_option === 'จัดส่ง') {
        finalFormData.append('shipping_address', registrationData.userInfo.shipping_address || '');
    }

    if (slipFile) {
        finalFormData.append('payment_slip', slipFile);
    }

    if (registrationData.userInfo) {
        for (const key in registrationData.userInfo) {
            // Avoid appending fields we already handle separately
            if (key !== 'payment_slip' && key !== 'shipping_option' && key !== 'shipping_address') {
                finalFormData.append(key, registrationData.userInfo[key]);
            }
        }
    } else {
        showMessage('เกิดข้อผิดพลาด', 'ข้อมูลผู้สมัครไม่ครบถ้วน กรุณาลองย้อนกลับไปขั้นตอนก่อนหน้า');
        return;
    }

    const nextBtn = document.getElementById('next-btn');
    if (!nextBtn) return;
    nextBtn.disabled = true;
    nextBtn.innerHTML = `<i class="fa-solid fa-spinner fa-spin mr-2"></i> กำลังส่งข้อมูล...`;

    fetch('actions/process_registration.php', {
        method: 'POST',
        body: finalFormData
    })
    .then(response => {
        if (!response.ok) {
            return response.text().then(text => { throw new Error(text || `Server error ${response.status}`); });
        }
        return response.json();
    })
    .then(data => {
        if (data.success === true && data.redirect_url) {
            const currentUrl = new URL(window.location.href);
            const relativeRedirectPath = data.redirect_url.replace('../', '');
            const pathname = currentUrl.pathname;
            const basePath = pathname.substring(0, pathname.lastIndexOf('/') + 1);
            const finalRedirectUrl = `${currentUrl.origin}${basePath}${relativeRedirectPath}`;
            window.location.href = finalRedirectUrl;
        } else {
            nextBtn.disabled = false;
            nextBtn.innerHTML = payLaterEnabled ? `<i class="fa-solid fa-check-circle mr-2"></i> ยืนยันการสมัคร (ยังไม่ชำระเงิน)` : `<i class="fa-solid fa-check-circle mr-2"></i> ยืนยันการสมัคร`;
            showMessage('เกิดข้อผิดพลาด', data.message || 'ไม่สามารถบันทึกข้อมูลได้');
        }
    })
    .catch(error => {
        console.error('Error during fetch operation:', error);
        nextBtn.disabled = false;
        nextBtn.innerHTML = payLaterEnabled ? `<i class="fa-solid fa-check-circle mr-2"></i> ยืนยันการสมัคร (ยังไม่ชำระเงิน)` : `<i class="fa-solid fa-check-circle mr-2"></i> ยืนยันการสมัคร`;
        showMessage('การเชื่อมต่อล้มเหลว', 'เกิดข้อผิดพลาดในการส่งข้อมูล: ' + error.message);
    });
}

// --- Initial Render ---
document.addEventListener('DOMContentLoaded', () => {
    if (loggedInRunner) {
         registrationData.userInfo = { ...loggedInRunner };
         // [NEW] Pre-fill address for shipping if it exists
         if (loggedInRunner.address) {
            // Only prefill shipping_address if it's not already set (e.g., from coming back from step 3)
            if (!registrationData.userInfo.shipping_address) {
                registrationData.userInfo.shipping_address = loggedInRunner.address;
            }
         }
         if (registrationData.shirt_size) {
            registrationData.userInfo.shirt_size = registrationData.shirt_size;
         }
    }
    renderCurrentStep();
});
</script>