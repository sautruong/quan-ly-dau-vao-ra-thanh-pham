$(document).ready(function () {

    // Helper: parse number from formatted string like "74,616 đ" -> 74616
    function parsePrice(str) {
        return parseFloat(String(str).replace(/[^\d.]/g, '')) || 0;
    }

    // Helper: format number to price string "74,616 đ" (làm tròn nghìn)
    function formatPrice(num) {
        num = Math.round(num / 1000) * 1000;
        return num.toLocaleString('vi-VN') + ' đ';
    }

    // Helper: format step (2 decimal places)
    function formatStep(num) {
        return parseFloat(num).toFixed(2);
    }

    // Flash animation cho các label (span)
    function flashElements($elements) {
        $elements.each(function () {
            var $el = $(this);
            $el.addClass('input-flash');
            setTimeout(function () {
                $el.removeClass('input-flash');
            }, 800);
        });
    }

    // Hàm lấy giá trị thời gian thực từ giao diện
    function getCurrentPrices() {
        return {
            capital:   parsePrice($('.capital-price').text()),
            wholesale: parsePrice($('.wholesale-price').val()),
            retail:    parsePrice($('.retail-price').val())
        };
    }

    // Hàm tính toán và cập nhật tất cả step dựa trên giá trị hiện tại trên giao diện
    function recalcAllSteps() {
        var prices = getCurrentPrices();
        var $step12 = $('.step-1-2');
        var $step23 = $('.step-2-3');
        var $step13 = $('.step-1-3');

        var step12 = (prices.capital > 0)   ? formatStep(prices.wholesale / prices.capital)   : '0.00';
        var step23 = (prices.wholesale > 0) ? formatStep(prices.retail / prices.wholesale)    : '0.00';
        var step13 = (prices.capital > 0)   ? formatStep(prices.retail / prices.capital)      : '0.00';

        $step12.text(step12);
        $step23.text(step23);
        $step13.text(step13);

        return { $step12: $step12, $step23: $step23, $step13: $step13 };
    }

    // ===== Khi thay đổi wholesale-price =====
    $('.wholesale-price').on('change', function () {
        // Bước 1: Format lại value input ngay lập tức
        var raw = parsePrice($(this).val());
        $(this).val(formatPrice(raw));

        // Bước 2: Lấy giá trị thời gian thực từ giao diện và tính step
        var steps = recalcAllSteps();

        // Bước 3: Flash chỉ step-1-2 và step-2-3
        flashElements(steps.$step12.add(steps.$step23));
    });

    // ===== Khi thay đổi retail-price =====
    $('.retail-price').on('change', function () {
        // Bước 1: Format lại value input ngay lập tức
        var raw = parsePrice($(this).val());
        $(this).val(formatPrice(raw));

        // Bước 2: Lấy giá trị thời gian thực từ giao diện và tính step
        var steps = recalcAllSteps();

        // Bước 3: Flash chỉ step-2-3 và step-1-3
        flashElements(steps.$step23.add(steps.$step13));
    });

});
