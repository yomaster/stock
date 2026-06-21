import Chart from 'chart.js/auto';
import Swal from 'sweetalert2';

// เปิดให้ blade @push scripts เรียกใช้ได้
window.Chart = Chart;
window.Swal = Swal;

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
