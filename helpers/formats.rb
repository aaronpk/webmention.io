class Formats

  def self.links_to_json(links)
    link_array = []

    links.each do |link|
      obj = {
        source: link.href,
        verified: link.verified == true,
        verified_date: link.updated_at,
        id: link.id,
        private: link.is_private,
        data: {}
      }

      if !link.author_name.blank? || !link.author_url.blank? || !link.author_photo.blank?
        obj[:data][:author] = {}
        obj[:data][:author][:name] = link.author_name if link.author_name
        if !link.author_url.blank?
          obj[:data][:author][:url] = Microformats2::AbsoluteUri.new(link.author_url, base: link.href).absolutize
        else
          obj[:data][:author][:url] = nil
        end
        if !link.author_photo.blank?
          obj[:data][:author][:photo] = Microformats2::AbsoluteUri.new(link.author_photo, base: link.href).absolutize
        else
          obj[:data][:author][:photo] = nil
        end
      end

      obj[:data][:url] = link.absolute_url
      obj[:data][:name] = link.name
      obj[:data][:content] = link.content.blank? ? link.content_text : link.content
      obj[:data][:published] = link.published_date
      obj[:data][:published_ts] = link.published_ts

      if ["rsvp-yes","rsvp-no","rsvp-maybe"].include? link.type
        obj[:data][:rsvp] = link.type.gsub("rsvp-","")
      end

      if link.swarm_coins
        obj[:data][:swarm_coins] = link.swarm_coins
      end

      obj[:activity] = {
        :type => (link.type ? link.type.gsub(/rsvp-.*/,"rsvp") : link.type),
        :sentence => link.sentence,
        :sentence_html => link.sentence_html
      }

      obj[:target] = link.page.href

      link_array << obj
    end

    {links: link_array}
  end

  def self.links_to_jf2(links)
    jf2 = {
      type: "feed",
      name: "Webmentions",
      children: []
    }

    links.each do |link|
      jf2[:children] << self.build_jf2_from_link(link)
    end

    jf2
  end

  def self.build_jf2_from_link(link)
    if link.published
      # Convert the date to a date with timezone offset
      time = link.published.to_time + (link.published_offset ? link.published_offset : 0)
      published = time.strftime('%Y-%m-%dT%H:%M:%S')

      if !(link.published_offset === nil)
        # only add the timezone offset if there is a non-null offset in the database
        offset_hours = sprintf('%+03d', ((link.published_offset) / 60 / 60).floor)
        offset_minutes = sprintf('%02d', ((link.published_offset) / 60 % 60))
        published = "#{published}#{offset_hours}:#{offset_minutes}"
      end
    else
      published = nil
    end

    received = link.created_at.to_time.strftime('%Y-%m-%dT%H:%M:%SZ')

    jf2 = {
      type: "entry",
      author: {
        type: "card",
        name: link.author_name,
        photo: link.author_photo,
        url: link.author_url
      },
      url: link.absolute_url,
      published: published,
      "wm-received": received
    }

    if !link.name.blank?
      jf2[:name] = link.name
    end

    if !link.syndications.nil?
      jf2[:syndication] = link.syndications
    end

    if !link.summary.blank?
      jf2[:summary] = {
        :"content-type" => "text/plain",
        :value => link.summary
      }
    end

    jf2[:photo] = JSON.parse(link.photo) if !link.photo.blank?
    jf2[:video] = JSON.parse(link.video) if !link.video.blank?
    jf2[:audio] = JSON.parse(link.audio) if !link.audio.blank?

    # 2017-07-06 switching to `html` and `text` properties according to the latest jf2 spec.
    # 2018-02-26 new sites created after this date will not have content-type/value properties
    content_deprecation_date = DateTime.parse("2018-02-26T17:00:00Z")
    if !link.content.blank?
      if link.site && link.site.created_at > content_deprecation_date
        jf2[:content] = {
          :html => link.content,
          :text => link.content_text
        }
      else
        jf2[:content] = {
          :"content-type" => "text/html",
          :value => link.content,
          :html => link.content,
          :text => link.content_text
        }
      end
    elsif !link.content_text.blank?
      if link.site && link.site.created_at > content_deprecation_date
        jf2[:content] = {
          :text => link.content_text
        }
      else
        jf2[:content] = {
          :"content-type" => "text/plain",
          :value => link.content_text,
          :text => link.content_text
        }
      end
    end

    if link.swarm_coins
      jf2[:'swarm-coins'] = link.swarm_coins
    end

    relation = nil

    case link.type
    when "like"
      relation = :"like-of"
    when "repost"
      relation = :"repost-of"
    when "reply"
      relation = :"in-reply-to"
    when "bookmark"
      relation = :"bookmark-of"
    when "rsvp-yes"
      relation = :"rsvp"
      jf2[:rsvp] = "yes"
      jf2[:"in-reply-to"] = link.page.href
    when "rsvp-no"
      relation = :"rsvp"
      jf2[:rsvp] = "no"
      jf2[:"in-reply-to"] = link.page.href
    when "rsvp-maybe"
      relation = :"rsvp"
      jf2[:rsvp] = "maybe"
      jf2[:"in-reply-to"] = link.page.href
    when "rsvp-interested"
      relation = :"rsvp"
      jf2[:rsvp] = "interested"
      jf2[:"in-reply-to"] = link.page.href
    else
      relation = :"mention-of"
    end

    if relation != :"rsvp"
      jf2[relation] = link.page.href
    end

    jf2[:'wm-property'] = relation
    jf2[:'wm-private'] = link.is_private

    jf2
  end

  def self.links_to_atom(links)
    base_url = "https://webmention.io"
    atom_url = "#{base_url}/api/mentions.atom"

    feed = Atom::Feed.new{|f|
      f.title = "Mentions"
      f.links << Atom::Link.new(:href => atom_url)
      f.updated = links.collect{|l| l.updated_at}.max
      f.authors << Atom::Person.new(:name => "webmention.io")
      f.id = atom_url

      links.each do |link|
        source = URI.parse link.href
        target = URI.parse link.page.href
        target.path = "/" if target.path == ""

        f.entries << Atom::Entry.new do |entry|
          entry.title = "#{source.host} linked to #{target.path}"
          entry.id = "#{base_url}/api/mention/#{link.id}"
          entry.updated = link.updated_at
          entry.summary = "#{source} linked to #{target}"

          escaped_source = CGI::escapeHTML(source.to_s)
          escaped_target = CGI::escapeHTML(target.to_s)
          entry.content = Atom::Content::Xhtml.new("<p><a href=\"#{escaped_source}\">#{escaped_source}</a> linked to <a href=\"#{escaped_target}\">#{escaped_target}</a></p>")
        end
      end
    }

    feed.to_xml
  end

end
