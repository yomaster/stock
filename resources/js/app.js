import Chart from 'chart.js/auto';
import Swal from 'sweetalert2';
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';

// เปิดให้ blade @push scripts เรียกใช้ได้
window.Chart = Chart;
window.Swal = Swal;

// เดือนไทยสำหรับ date picker
const THAI_MONTHS = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];

// แปลง input[type=date] ทั้งหมดเป็น flatpickr แสดง พ.ศ. ภาษาไทย (เก็บ value เป็น Y-m-d)
window.initThaiDatePickers = function (root = document) {
    root.querySelectorAll('input[type="date"]:not([data-fp])').forEach(function (el) {
        el.dataset.fp = '1';
        flatpickr(el, {
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'thaibuddhist',
            allowInput: false,
            maxDate: el.max || null,
            locale: {
                weekdays: {
                    shorthand: ['อา','จ','อ','พ','พฤ','ศ','ส'],
                    longhand: ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'],
                },
                months: {
                    shorthand: THAI_MONTHS.map(m => m.substring(0, 3)),
                    longhand: THAI_MONTHS,
                },
            },
            // แสดงผลในช่อง altInput เป็น "21 มิถุนายน 2569"
            formatDate: function (date, format) {
                if (format === 'thaibuddhist') {
                    return date.getDate() + ' ' + THAI_MONTHS[date.getMonth()] + ' ' + (date.getFullYear() + 543);
                }
                const y = date.getFullYear();
                const m = String(date.getMonth() + 1).padStart(2, '0');
                const d = String(date.getDate()).padStart(2, '0');
                return `${y}-${m}-${d}`;
            },
        });
    });
};

// Toast แจ้งเตือนมุมขวาบน (ใช้กับ บันทึก/เพิ่ม/ลบ สำเร็จหรือล้มเหลว)
window.toast = function (icon, title) {
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: icon,            // 'success' | 'error' | 'warning' | 'info'
        title: title,
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        customClass: { popup: 'rounded-2xl shadow-lg' },
    });
};

document.addEventListener('DOMContentLoaded', function () {
    // แปลง date input เป็น Thai date picker
    window.initThaiDatePickers();

    // แสดง flash message จาก server (set ผ่าน window.__flash ใน layout)
    if (window.__flash && window.__flash.msg) {
        window.toast(window.__flash.type || 'success', window.__flash.msg);
    }

    // ยืนยันการลบแบบ SweetAlert สำหรับทุก form.confirm-delete
    document.addEventListener('submit', function (e) {
        const form = e.target.closest('form.confirm-delete');
        if (!form || form.dataset.confirmed === '1') return;

        e.preventDefault();
        Swal.fire({
            title: form.dataset.title || 'ยืนยันการลบ?',
            text: form.dataset.message || 'การลบนี้ไม่สามารถย้อนกลับได้',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: 'ลบเลย',
            cancelButtonText: 'ยกเลิก',
            reverseButtons: true,
            customClass: { popup: 'rounded-2xl' },
        }).then(function (result) {
            if (result.isConfirmed) {
                form.dataset.confirmed = '1';
                form.submit();
            }
        });
    });
});
