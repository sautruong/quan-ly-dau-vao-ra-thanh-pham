
document.querySelector('.print').addEventListener('click', function () {
    window.print();
});
document.querySelector('.save-pdf').addEventListener('click', function () {

    const element = document.querySelector('.contract');

    // 👇 LƯU STYLE GỐC
    const originalTransform = element.style.transform;
    const originalBg = element.style.background;

    // 👇 FIX LỖI RENDER
    element.style.transform = 'scale(1)';
    element.style.margin = '0';
    element.style.display = 'block';
    element.style.background = '#ffffff';

    document.querySelectorAll('.page').forEach(p => {
        p.style.boxShadow = 'none'; // 👈 QUAN TRỌNG
    });

    const safeName = employeeName
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .replace(/đ/g, "d")
        .replace(/Đ/g, "D")
        .replace(/[^a-zA-Z0-9]/g, "-")
        .replace(/-+/g, "-")
        .toLowerCase();

    const today = new Date().toISOString().slice(0, 10);

const opt = {
      margin: [-8, 0, 0, 0], // 👈 top, left, bottom, right (mm)
    filename: `hop-dong-${safeName}-${today}.pdf`,

    image: { type: 'jpeg', quality: 1 },

    html2canvas: {
        scale: 2,
        scrollY: 0
    },

    jsPDF: {
        unit: 'mm',
        format: 'a4',
        orientation: 'portrait'
    }
};

    html2pdf().set(opt).from(element).save().then(() => {

        // 👇 TRẢ LẠI STYLE BAN ĐẦU
        element.style.transform = originalTransform;
        element.style.background = originalBg;

        document.querySelectorAll('.page').forEach(p => {
            p.style.boxShadow = '';
        });
    });
});