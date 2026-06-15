/**
 * �ص���������ʹ�� document.write/writeln���������ĵ��ѽ�����������ҳ��
 */
(function () {
  if (!document.body) return;

  var css =
    '.bar-top-FZdsN{bottom:20px;right:6px;height:56px;border-radius:6px;box-shadow:0 4px 6px 0 rgb(184 208 255 / 30%);transition:all .4s ease;cursor:pointer;background-size:cover;background-image:url(https://fbhbrgbrg.3366444.com/images/3c9300f26504de0b3cabadacefb461c5.png);}' +
    '.bar-bQ14J{position:fixed;z-index:98;width:56px;display:none;}';

  var style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  var bar = document.createElement('div');
  bar.className = 'bar-bQ14J bar-top-FZdsN';
  bar.setAttribute('role', 'button');
  bar.setAttribute('aria-label', '返回顶部');
  bar.addEventListener('click', function () {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  document.body.appendChild(bar);

  window.addEventListener('scroll', function () {
    bar.style.display = window.scrollY >= 500 ? 'block' : 'none';
  });
})();
