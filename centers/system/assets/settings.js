/* 设置中心：选择 Logo 图片后在浏览器里读出数据、预览，再随表单上传。 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var input = document.querySelector('[data-logo-file]');
    if (!input) return;
    var data = document.querySelector('[data-logo-data]');
    var preview = document.querySelector('[data-logo-preview]');
    var submit = document.querySelector('[data-logo-submit]');
    var max = parseInt(input.getAttribute('data-max'), 10) || 45000;

    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      data.value = '';
      submit.disabled = true;
      if (!file) return;
      if (['image/png', 'image/jpeg', 'image/webp'].indexOf(file.type) === -1) {
        window.Centers.toast('请选择 PNG、JPG 或 WebP 图片', 'error');
        input.value = '';
        return;
      }
      if (file.size > max) {
        window.Centers.toast('图片太大（' + Math.round(file.size / 1000) + 'KB），请压缩到 ' + Math.round(max / 1000) + 'KB 以内', 'error');
        input.value = '';
        return;
      }
      var reader = new FileReader();
      reader.onload = function () {
        data.value = String(reader.result || '');
        preview.innerHTML = '';
        var img = document.createElement('img');
        img.src = data.value;
        img.alt = '新 Logo 预览';
        preview.appendChild(img);
        submit.disabled = false;
      };
      reader.readAsDataURL(file);
    });
  });
})();
