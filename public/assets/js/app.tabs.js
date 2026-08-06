/**
 * 分頁籤。
 *
 * Bootstrap 的 tab 行為本身夠用，這裡只補兩件事：
 *
 *   1. 延遲載入：標了 data-lazy 的頁籤，第一次被打開時才去查資料。
 *      不然一進頁面就同時打三支報表 API，資料庫會很痛苦。
 *
 *   2. 表格寬度校正：DataTables 在隱藏的容器裡算不出正確的欄寬，
 *      切換到該頁籤時要重算一次，否則欄位會擠成一團。
 */
(function (App) {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-bs-toggle="tab"]'),
            function (btn) {
                btn.addEventListener('shown.bs.tab', function () {
                    var paneId = (btn.getAttribute('data-bs-target') || '').replace('#', '');
                    var pane   = document.getElementById(paneId);
                    if (!pane) return;

                    Array.prototype.forEach.call(
                        pane.querySelectorAll('[data-table-config]'),
                        function (wrap) {
                            var config   = App.readConfig(wrap, 'data-table-config');
                            var instance = config && App.table.get(config.id);
                            if (!instance) return;

                            // 延遲載入的表格，第一次打開時才查
                            if (btn.getAttribute('data-lazy') === '1' && !instance.loaded) {
                                var form = document.querySelector(
                                    '.app-filter[data-filter-target*="' + config.id + '"]'
                                );
                                instance.reload(form ? App.serialize(form) : null);
                                return;
                            }

                            // 已載入過的，只要重算欄寬
                            instance.dt.columns.adjust();
                        }
                    );
                });
            }
        );
    });

})(window.App);
