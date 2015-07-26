# Webmention.io

This project is an implementation of the [Webmention](http://indiewebcamp.com/webmention) and [Pingback](http://indiewebcamp.com/pingback) protocols. It allows the receiving service to be run separately from the blogging software or website environment, making it easier to manage and integrate with other services.

Say you have a statically-generated website using Jekyll or something similar, you can simply add the appropriate `<link>` tags to this service, and now you have WebMention and Pingback enabled on your static site!

    <link rel="pingback" href="http://webmention.io/username/xmlrpc" />
    <link rel="http://webmention.org/" href="http://webmention.io/username/webmention" />

The Webmention and Pingback protocols also support specifying the endpoint in the headers,

    Link: <http://webmention.io/username/webmention>; rel="http://webmention.org/"
    X-Pingback: http://webmention.io/username/xmlrpc


## Features

* Accept Webmentions for any site by adding a simple html tag: `<link rel="http://webmention.org/" href="http://webmention.io/username/webmention" />`
* Accept Pingbacks for any site by adding a simple html tag: `<link rel="pingback" href="http://webmention.io/username/xmlrpc" />`
* API to get a list of pages linking to your site or a specific page
* If you want to receive Pingbacks on your site but don't want to deal with XMLRPC, then you can use this service to convert Pingbacks to Webmentions


### Future Features

* Provide an API method for sending outgoing pingbacks from your own site to pages you link to

## API

### Find links to a specific page

This service provides an API for returning a list of pages that have linked to a given page. For example:

```
GET http://webmention.io/api/mentions?target=http://indiewebcamp.com

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

### Find links to multiple pages

This is useful for retrieving mentions from a post if you've changed the URL.

```
GET http://webmention.io/api/mentions?target[]=http://indiewebcamp.com/a-blog-post&target[]=http://indiewebcamp.com/a-different-post

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
GET http://webmention.io/api/mentions?domain=indiewebcamp.com

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
GET http://webmention.io/api/mentions

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

The API also supports JSONP so you can use it to show pingbacks on your own sites via Javascript. Simply add a parameter `jsonp` to the API call, for example, http://webmention.io/api/mentions?jsonp=f&target=http%3A%2F%2Fwebmention.io


## Notifications

### IRC

If you are running an instance of [ZenIRCBot](https://github.com/wraithan/zenircbot), you can use it to receive IRC notifications when a new webmention or pingback is received. You'll need to be running the [web-proxy](https://github.com/aaronpk/zenircbot/blob/master/services/web-proxy.js) service, and then you can configure the URL and channel the message should be delivered to.

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



## Pingback to Webmention Service

[Webmention](http://webmention.org) is a modern alternative to Pingback. It's analogous to the Pingback protocol except does not use XML-RPC and is much easier to implement. This project also includes a simple API for converting XML-RPC Pingbacks to WebMentions and forwarding the request on to your own site.

Using Webmention.io in this mode does not require an registration, and this service does not store any of the information. The Pingback request is simply forwarded on to your server as a Webmention.

To use, add a Pingback header like the following:

    <link rel="pingback" href="http://webmention.io/webmention?forward=http://example.com/webmention" />

Any Pingbacks received will be forwarded on to the specified Webmention endpoint. It is up to you to handle the Webmention and return an expected result. The Webmention result will be converted to a Pingback result and passed back to the sender.

### Full Example

#### A blog links to your site, makes a GET request for the page to retrieve the Pingback header

```
GET http://example.com/post/1000

<html>
  <head>
    <title>Example Post 1000</title>
    <link rel="pingback" href="http://webmention.io/webmention?forward=http://example.com/webmention" />
    ...
```

#### The blog sends a Pingback request to webmention.io

```
POST http://webmention.io/webmention?forward=http://example.com/webmention
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

#### The webmention.io server forwards this on to your site as a Webmention

```
POST http://example.com/webmention
Content-Type: application/x-www-url-form-encoded

source=http://aaronparecki.com/notes/2013/02/16/1/little-printer&
target=http://example.com/post/1000
```

#### Your server replies with a Webmention response indicating success

```
HTTP/1.1 202 Accepted
Content-Type: application/json

{
  "result": "Webmention was successful"
}
```

#### Webmention.io converts this to a Pingback success reply and sends it back to the original blog

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

Webmention errors are converted to Pingback errors as well! For example,

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

You can start using this right now to quickly handle Pingbacks as Webmentions on your own domain. This is a way to bootstrap the Webmention protocol until more services adopt it.


## Development

First, check your Ruby version. 2.0.0 _does not_ work; [details below](#ruby-200-woes). Try 1.9.3 or anything >=2.1.3 instead, they should work. Here are minimal instructions for Mac OS X, using [Homebrew](http://brew.sh/), [ruby-install](https://github.com/postmodern/ruby-install), and [chruby](https://github.com/postmodern/chruby):

```sh
brew install ruby-install chruby libxml2 libxslt
ruby-install ruby 2.1.6
source /usr/local/opt/chruby/share/chruby/chruby.sh
chruby 2.1.6
gem install bundler
```

Now, run these commands to set up your environment and start the server locally:

```shell
bundle install
cp config.yml.template config.yml
mysql -u root -e 'CREATE USER webmention@localhost IDENTIFIED BY "webmention"; CREATE DATABASE webmention; GRANT ALL ON webmention.* TO webmention@localhost; FLUSH PRIVILEGES;'
export RACK_ENV=development
bundle exec rake db:bootstrap
./start.sh
```

Now open http://localhost:9019/ and check that you see the front page. You can also run `bundle exec rake test:sample1` to send a test pingback.


### Troubleshooting

If `bundle install` dies like this while compiling libxml-ruby:

```
...
ruby_xml_node.c:624:56: error: incomplete definition of type 'struct _xmlBuf'
    result = rxml_new_cstr((const char*) output->buffer->content, xencoding);
                                         ~~~~~~~~~~~~~~^
...
An error occurred while installing libxml-ruby (2.3.3), and Bundler cannot continue.
Make sure that `gem install libxml-ruby -v '2.3.3'` succeeds before bundling.
```

You're in...um...a weird state. You probably have an old version of the repo checked out with a `Gemfile.lock` that asks for libxml-ruby 2.3.3, [which is incompatible with your system's libxml2 2.9.x](http://stackoverflow.com/a/19781873/186123). HEAD fixes this by asking for libxml-ruby 2.6.0. `git pull` and then rerun `bundle install`.

If `bundle install` dies with this message in the middle of its error output:

```
/.../lib/ruby/2.0.0/tmpdir.rb:92:in `mktmpdir': parent directory is world writable but not sticky (ArgumentError)
```

...you can fix this with either `chmod +t $TMPDIR` or (better) `chmod 700 $TMPDIR`. [Evidently this problem is common on Mac OS X.](http://stackoverflow.com/a/30269211/186123)

When you open the front page, if you see an error that includes _Library not loaded: libmysqlclient.18.dylib_, your MySQL shared libraries may not be installed at a standard location, e.g. if you installed MySQL via Homebrew. Try `DYLD_LIBRARY_PATH=/usr/local/mysql/lib ./start.sh` (or wherever your MySQL libraries are located).

### Ruby 2.0.0 woes
If `rake db:bootstrap` raises an _TypeError: no implicit conversion from nil to integer_ exception in `quoting.rb`, you've hit [this Ruby 2.0.0 bug/incompatibility](http://stackoverflow.com/a/25101398/186123). Use a different Ruby version.

If `rake db:bootstrap` hangs while attempting to create the `links` table , Ruby 2.0.0 strikes again! Use a different version. (You won't see progress details per table by default; it'll just hang.)

If `bundle exec rake ...` complains _Could not find rake-10.4.0 in any of the sources_, and you run `bundle install` and `bundle check` and they're both happy, and `vendor/bundle/ruby/2.0.0/gems/rake-10.4.0/` exists...Ruby 2.0.0 strikes again. (Maybe?) Use a different version.


## License

Copyright 2013 by Aaron Parecki.

Available under the BSD License. See LICENSE.txt

