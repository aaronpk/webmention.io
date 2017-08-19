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
      time = link.published_date.to_time + (link.published_offset ? link.published_offset : 0)
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

    jf2 = {
      type: "entry",
      author: {
        type: "card",
        name: link.author_name,
        photo: link.author_photo,
        url: link.author_url
      },
      url: link.absolute_url,
      published: published
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

    # 2017-07-06 switching to `html` and `text` properties according to the latest jf2 spec.
    # TODO: Deprecate the `content-type` and `value` properties in the future.
    if !link.content.blank?
      jf2[:content] = {
        :"content-type" => "text/html",
        :value => link.content,
        :html => link.content,
        :text => link.content_text
      }
    elsif !link.content_text.blank?
      jf2[:content] = {
        :"content-type" => "text/plain",
        :value => link.content_text,
        :text => link.content_text
      }
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

end
