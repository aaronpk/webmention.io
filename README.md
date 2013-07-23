# Pingbacks

This project is an implementation of the [Pingback](http://indiewebcamp.com/pingback) and [WebMention](http://indiewebcamp.com/webmention) protocols. It allows the receiving service to be run separately from the blogging software or website environment, making it easier to manage and integrate with other services.

Say you have a statically-generated website using Jekyll or something similar, you can simply add the appropriate <link> tags to this service, and now you have WebMention and Pingback enabled on your static site!

    <link rel="pingback" href="http://pingback.me/username/xmlrpc" />
    <link rel="http://webmention.org/" href="http://pingback.me/username/webmention" />

The Pingback protocol also supports specifying the URL in the headers,

    X-Pingback: http://pingback.me/username/xmlrpc

WebMention also supports specifying the URL in the headers,

    Link: <http://pingback.me/username/webmention>; rel="http://webmention.org/"


## Features

* Accept Pingbacks for any site by adding a simple html tag: `<link rel="pingback" href="http://pingback.me/username/xmlrpc" />`
* Accept WebMentions for any site by adding a simple html tag: `<link rel="http://webmention.org/" href="http://pingback.me/username/webmention" />`
* API to get a list of pages linking to your site or a specific page
* If you want to receive WebMentions on your site directly, you can use this service to convert Pingbacks to WebMentions


### Future Features

* Provide an API method for sending outgoing pingbacks from your own site to pages you link to

## API

### Find links to a specific page

This service provides an API for returning a list of pages that have linked to a given page. For example:

```
GET http://pingback.me/api/links?target=http://indiewebcamp.com

{
  "links": [
    {
      "source": "http://tantek.com/2013/113/b1/first-federated-indieweb-comment-thread",
      "verified": true,
      "verified_date": "2013-04-25T17:09:33-07:00"
    }
  ]
}
```

### Find all links to your domain

You can also find all links to your domain:

```
GET http://pingback.me/api/links?domain=indiewebcamp.com

{
  "links": [
    {
      "source": "http://tantek.com/2013/113/b1/first-federated-indieweb-comment-thread",
      "verified": true,
      "verified_date": "2013-04-25T17:09:33-07:00",
      "target": "http://indiewebcamp.com/webmention"
    }
  ]
}
```

### Find all links to all sites in your account

With no parameters, the API will return all links to any site in your account:

```
GET http://pingback.me/api/links

{
  "links": [
    {
      "source": "http://tantek.com/2013/113/b1/first-federated-indieweb-comment-thread",
      "verified": true,
      "verified_date": "2013-04-25T17:09:33-07:00",
      "target": "http://indiewebcamp.com/webmention"
    }
  ]
}
```


### Paging

Basic paging is supported by using the `perPage` and `page` parameters. For example,

* `?perPage=20&page=0` first page of 20 results
* `?perPage=20&page=1` second page of 20 results

The default number of results per page is 20. Results are always sorted newest first.


### JSONP

The API also supports JSONP so you can use it to show pingbacks on your own sites via Javascript. Simply add a parameter `jsonp` to the API call, for example, http://pingback.me/api/links?jsonp=f&target=http%3A%2F%2Fpingback.me


## Notifications

### IRC

If you are running an instance of [ZenIRCBot](https://github.com/wraithan/zenircbot), you can use it to receive IRC notifications when a new pingback is received. You'll need to be running the [web-proxy](https://github.com/aaronpk/zenircbot/blob/master/services/web-proxy.js) service, and then you can configure the URL and channel the message should be delivered to.

### Jabber

You may receive a notification using XMPP. Configure the Jabber account to send notifications from (JID as `foo@bar.tld/Resource` and password) as well as the JID the messages should be sent to. Jabber notification has to be enabled on a per-site basis.

## About the Pingback Protocol

The pingback system is a way for a blog to be automatically notified when other Web sites link to it. It is entirely transparent to the linking author, requiring no user intervention to work, and operates on principles of automatic discovery of everything that it needs to know.

A sample blog post involving pingback might go like this:

* Alice posts to her blog. The post she's made includes a link to a post on Bob's blog.
* Alice's blogging system contacts Bob's blogging system and says "look, Alice made a post which linked to one of your posts".
* Bob's blogging system then includes a link back to Alice's post on his original post.
* Reader's of Bob's article can follow this link to Alice's post to read her opinion.

Read the full protocol here: http://www.hixie.ch/specs/pingback/pingback



## Pingback to WebMention Service

[WebMention](http://webmention.org) is a modern alternative to Pingback. It's analogous to the Pingback protocol except does not use XML-RPC and is much easier to implement. This project also includes a simple API for converting XML-RPC Pingbacks to WebMentions and forwarding the request on to your own site.

Using Pingback.me in this mode does not require an account, and this service does not store any of the information. The Pingback request is simply forwarded on to your server as a WebMention.

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


## License

Copyright 2013 by Aaron Parecki. 

Available under the BSD License. See LICENSE.txt

