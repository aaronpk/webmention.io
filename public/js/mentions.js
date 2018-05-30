jQuery(function($){

  $("*[data-webmention-count]").each(function(i,e){
    var parser = document.createElement('a');
    target = $(e).data('url');
  });

  $.getJSON("https://webmention.io/api/count?jsonp=?", {
    targets: target
  }, function(data){
    $("*[data-webmention-count]").each(function(i,e){
      $(e).text(data.count);
    });
  });
});
