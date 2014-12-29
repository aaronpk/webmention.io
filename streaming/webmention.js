var WebSocketServer = require('ws').Server;
var Redis = require('redis');

var port = 8078;

var wss = new WebSocketServer({port: port});

wss.on('connection', function(ws) {
  // console.log("New websockets connection");
  ws.on('message', function(message) {
    var redis = Redis.createClient(6379, 'localhost');
    var channel = 'webmention.io::' + message;
    redis.subscribe(channel);
    // console.log('Listening for comments on channel ' + channel);
    redis.on('message', function (channel, message) {
      console.log('Sent comment to channel ' + channel);
      ws.send(message);
    });
    ws.on('close', function(){
      // console.log('Killing listener for channel ' + channel);
      redis.unsubscribe();
      redis.end();
    });
    ws.on('error', function(){
      // console.log('Killing listener for channel ' + channel);
      redis.unsubscribe();
      redis.end();
    });
  });
});

console.log("WebSocket Server Listening on port "+port);
