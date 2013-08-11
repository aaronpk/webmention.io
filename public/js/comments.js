jQuery(function($){
  var urls = [];
  var base;

  $("*[data-indieweb-comment-count]").each(function(i,e){
    var parser = document.createElement('a');
    parser.href = $(e).data('url');
    base = parser.protocol + "//" + parser.hostname;
    urls.push(parser.pathname+parser.search);
  });

  $.getJSON("http://pingback.dev/api/count?jsonp=?", {
    base: base,
    targets: urls.join(",")
  }, function(data){
    $("*[data-indieweb-comment-count]").each(function(i,e){
      $(e).text(data.count[$(e).data('url')]);
    });

    $(data.count).each(function(i,e){
      console.debug(e);
    });
  });
});
