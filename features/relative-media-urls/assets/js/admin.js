(function(){
  document.addEventListener('click', function(e){
    if(e.target && e.target.classList.contains('jrmu-check-all')){
      document.querySelectorAll('.jrmu-post-check').forEach(function(el){el.checked = e.target.checked;});
    }
    if(e.target && e.target.classList.contains('jrmu-copy-command')){
      var id=e.target.getAttribute('data-copy-id');
      var textarea=id && document.getElementById(id);
      if(!textarea){return;}
      textarea.focus();
      textarea.select();
      var didCopy=false;
      try{didCopy=document.execCommand('copy');}catch(err){}
      e.target.textContent=didCopy?'已复制命令':'复制失败，请手动选择';
      return;
    }
    if(e.target && e.target.classList.contains('jrmu-copy-nginx')){
      var ta=document.querySelector('.jrmu-nginx');
      if(!ta){return;}
      ta.select();
      try{document.execCommand('copy');}catch(err){}
      var span=document.createElement('span');
      span.className='jrmu-copy-ok';
      span.textContent='已复制';
      e.target.parentNode.appendChild(span);
      setTimeout(function(){span.remove();},1800);
    }
  });
})();
