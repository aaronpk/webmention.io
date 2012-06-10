Pingbacks
=========

This project is an implementation of the Pingback protocol. It allows the pingback service to be run separately from the blogging software or website environment, making it easier to manage and integrate with other services.

Say you have a statically-generated website using Jekyll or something similar, you can simply add the appropriate <link> tags to this service, and now you have pingbacks enabled on your static site!

    <link rel="pingback" href="http://pingback.me/username/xmlrpc" />
    
The Pingback protocol also supports sending the URL in the headers,

    X-Pingback: http://pingback.me/username/xmlrpc

About the Pingback Protocol
---------------------------

The pingback system is a way for a blog to be automatically notified when other Web sites link to it. It is entirely transparent to the linking author, requiring no user intervention to work, and operates on principles of automatic discovery of everything that it needs to know.

A sample blog post involving pingback might go like this:

* Alice posts to her blog. The post she's made includes a link to a post on Bob's blog.
* Alice's blogging system contacts Bob's blogging system and says "look, Alice made a post which linked to one of your posts".
* Bob's blogging system then includes a link back to Alice's post on his original post.
* Reader's of Bob's article can follow this link to Alice's post to read her opinion.

Read the full protocol here: http://www.hixie.ch/specs/pingback/pingback

