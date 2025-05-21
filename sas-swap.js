jQuery(document).ready(function($) {

    function bindSwapButtons() {
        $('.sas-swap-button').off('click').on('click', function(e) {
            e.preventDefault();

            const button = $(this);

            if (button.data('swapping')) return; // Debounce multiple clicks
            button.data('swapping', true);

            const original_id = button.data('original');
            const swap_id = button.data('swap');
            const cartItemKey = button.data('key');
            const row = button.closest('tr');

            // Disable the button and add a spinner
            button.prop('disabled', true).css('opacity', 0.6).html('<span class="spinner" style="display:inline-block;width:16px;height:16px;border:2px solid #ccc;border-top-color:#000;border-radius:50%;animation:spin 1s linear infinite;"></span>');

            // Skeleton loader row
            const previewRow = $('<tr class="preview-row">')
                .append('<td class="product-remove"></td>')
                .append('<td class="product-thumbnail"><div class="blurred-image" style="width:50px;height:50px;background:#eee;border-radius:4px;"></div></td>')
                .append('<td class="product-name"><div class="skeleton-name" style="background:#ccc;height:16px;width:100px;border-radius:4px;"></div></td>')
                .append('<td class="product-price"><div class="skeleton-price" style="background:#ccc;height:16px;width:60px;border-radius:4px;"></div></td>')
                .append('<td class="product-quantity"><div class="skeleton-quantity" style="background:#ccc;height:16px;width:40px;border-radius:4px;"></div></td>')
                .append('<td class="product-subtotal"><div class="skeleton-subtotal" style="background:#ccc;height:16px;width:60px;border-radius:4px;"></div></td>');

            row.slideUp(200, function() {
                $(this).replaceWith(previewRow.hide().slideDown(200));
            });

            fetch(sas_ajax.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'sas_swap_product',
                    original_id: original_id,
                    swap_id: swap_id,
                    cart_item_key: cartItemKey,
                    nonce: sas_ajax.nonce
                })
            })
            .then(response => response.json())
            .then(response => {
                if (!response.success) {
                    button.prop('disabled', false).css('opacity', 1).text('Try Again');
                    button.data('swapping', false);
                    return;
                }

                if (response.data.out_of_stock) {
                    previewRow.replaceWith(row.show());
                    alert('Selected product is out of stock.');
                    button.prop('disabled', false).css('opacity', 1).text('Swap');
                    button.data('swapping', false);
                    return;
                }

                if (response.data.reload) {
                    const existingRow = $('tr').filter(function () {
                        const productId = $(this).find('.remove').data('product_id');
                        return parseInt(productId) === swap_id;
                    }).first();

                    if (existingRow.length) {
                        const qtyInput = existingRow.find('input.qty');
                        const currentQty = parseInt(qtyInput.val()) || 1;
                        const newQty = currentQty + 1;
                        qtyInput.val(newQty);

                        const priceText = existingRow.find('.product-price .amount').text().replace(/[^\d.]/g, '');
                        const price = parseFloat(priceText);
                        if (!isNaN(price)) {
                            const newSubtotal = (price * newQty).toFixed(2);
                            existingRow.find('.product-subtotal .amount').text(`₹${newSubtotal}`);
                        }

                        previewRow.slideUp(200, function () {
                            $(this).remove();
                        });

                        refreshCartTotals();
                        return;
                    }

                    window.location.reload();
                    return;
                }

                // Regular swap
                fetch(window.location.href)
                .then(res => res.text())
                .then(html => {
                    const temp = $('<div>').html(html);

                    const swappedRow = temp.find('tr').filter(function () {
                        const productId = $(this).find('.remove').data('product_id');
                        return parseInt(productId) === swap_id;
                    }).first();

                    const newTotals = temp.find('.cart_totals');

                    if (swappedRow.length) {
                        swappedRow.css('background', '#ffffe0').hide(); // light yellow highlight
                        previewRow.replaceWith(swappedRow.fadeIn(300));

                        $('html, body').animate({
                            scrollTop: swappedRow.offset().top - 100
                        }, 400);
                    } else {
                        window.location.reload();
                        return;
                    }

                    if (newTotals.length) {
                        $('.cart_totals').replaceWith(newTotals.hide().fadeIn(300));
                    }

                    bindSwapButtons();
                    $(document.body).trigger('wc_fragment_refresh');
                });
            });
        });
    }

    function refreshCartTotals() {
        fetch(window.location.href)
            .then(res => res.text())
            .then(html => {
                const temp = $('<div>').html(html);
                const newTotals = temp.find('.cart_totals');
                if (newTotals.length) {
                    $('.cart_totals').replaceWith(newTotals.hide().fadeIn(300));
                }
                $(document.body).trigger('wc_fragment_refresh');
            });
    }

    bindSwapButtons();
});
