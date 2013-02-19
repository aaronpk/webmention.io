# Pingbacks

This project is an implementation of the Pingback protocol. It allows the pingback service to be run separately from the blogging software or website environment, making it easier to manage and integrate with other services.

Say you have a statically-generated website using Jekyll or something similar, you can simply add the appropriate <link> tags to this service, and now you have pingbacks enabled on your static site!

    <link rel="pingback" href="http://pingback.me/username/xmlrpc" />
    
The Pingback protocol also supports sending the URL in the headers,

    X-Pingback: http://pingback.me/username/xmlrpc


## Features

* Accept pingbacks for any site by adding a simple html tag: `<link rel="pingback" href="http://pingback.me/username/xmlrpc" />`
* API to get a list of sites linking to your pages


### Future Features

* Provide an API method for sending outgoing pingbacks from your own site to pages you link to


## WebMention

[WebMention](http://webmention.org) is a modern alternative to Pingback. It's analogous to the Pingback protocol except does not use XML-RPC and is much easier to implement. This project also includes a simple API for converting XML-RPC Pingbacks to WebMentions.

To use, add a Pingback header like the following:

    <link rel="pingback" href="http://pingback.me/webmention?forward=http://example.com/webmention" />

Any Pingbacks received will be forwarded on to the specified WebMention endpoint. It is up to you to handle the WebMention and return an expected result. The WebMention result will be converted to a Pingback result and passed back to the sender.

### Full Example

#### A blog links to your site, makes a GET request for the page to retrieve the Pingback header

```
GET http://example.com/post/1000

<html>
  <head>
    <title>Example Post 1000</title>
    <link rel="pingback" href="http://pingback.me/webmention?forward=http://example.com/webmention" />
    ...
```

#### The blog sends a Pingback request to pingback.me

```
POST http://pingback.me/webmention?forward=http://example.com/webmention
Content-Type: application/xml

<?xml version="1.0" ?>
<methodCall>
  <methodName>pingback.ping</methodName>
  <params>
    <param>
      <value>
        <string>http://aaronparecki.com/notes/2013/02/16/1/little-printer</string>
      </value>
    </param>
    <param>
      <value>
        <string>http://example.com/post/1000</string>
      </value>
    </param>
  </params>
</methodCall>
```

#### The pingback.me server forwards this on to your site as a WebMention

```
POST http://example.com/webmention
Content-Type: application/x-www-url-form-encoded

source=http://aaronparecki.com/notes/2013/02/16/1/little-printer&
target=http://example.com/post/1000
```

#### Your server replies with a WebMention response indicating success

```
HTTP/1.1 202 Accepted
Content-Type: application/json

{
  "result": "WebMention was successful"
}
```

#### Pingback.me converts this to a Pingback success reply and sends it back to the original blog

```
HTTP/1.1 200 OK
Content-Type: application/xml

<?xml version="1.0" ?>
<methodResponse>
  <params>
    <param>
      <value>
        <string>Pingback from http://aaronparecki.com/notes/2013/02/16/1/little-printer to http://example.com/post/1000 was successful!</string>
      </value>
    </param>
  </params>
</methodResponse>
```

#### Errors

WebMention errors are converted to Pingback errors as well! For example,

```
{
  "error": "no_link_found"
}
```

Is converted to:

```
<?xml version="1.0" ?>
<methodResponse>
  <fault>
    <value>
      <struct>
        <member>
          <name>faultCode</name>
          <value><i4>17</i4></value>
        </member>
        <member>
          <name>faultString</name>
          <value>
            <string>no_link_found</string>
          </value>
        </member>
      </struct>
    </value>
  </fault>
</methodResponse>
```

You can start using this right now to quickly handle Pingbacks as WebMentions on your own domain. This is a way to bootstrap the WebMention protocol until more services adopt it.


## API

This service provides an API for returning a list of pages that have linked to a given page. For example:

```
GET http://pingback.me/api/links?target=http://pingback.me/

{
  "links": [
    {
      "href": "http://indiewebcamp.com/pingback",
      "verified": true,
      "verified_date": "2013-01-27T19:05:37-08:00"
    }
  ]
}
```

The API also supports JSONP so you can use it to show pingbacks on your own sites via Javascript. Simply add a parameter `jsonp` to the API call, for example, http://pingback.me/api/links?jsonp=f&target=http%3A%2F%2Fpingback.me


## IRC Notifications

If you are running an instance of [ZenIRCBot](https://github.com/wraithan/zenircbot), you can use it to receive IRC notifications when a new pingback is received. You'll need to be running the [web-proxy](https://github.com/aaronpk/zenircbot/blob/master/services/web-proxy.js) service, and then you can configure the URL and channel the message should be delivered to.


## About the Pingback Protocol

The pingback system is a way for a blog to be automatically notified when other Web sites link to it. It is entirely transparent to the linking author, requiring no user intervention to work, and operates on principles of automatic discovery of everything that it needs to know.

A sample blog post involving pingback might go like this:

* Alice posts to her blog. The post she's made includes a link to a post on Bob's blog.
* Alice's blogging system contacts Bob's blogging system and says "look, Alice made a post which linked to one of your posts".
* Bob's blogging system then includes a link back to Alice's post on his original post.
* Reader's of Bob's article can follow this link to Alice's post to read her opinion.

Read the full protocol here: http://www.hixie.ch/specs/pingback/pingback

