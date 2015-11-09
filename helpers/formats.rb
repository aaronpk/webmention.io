class Formats

  def self.links_to_json(links)
    link_array = []

    links.each do |link|
      obj = {
        source: link.href,
        verified: link.verified == true,
        verified_date: link.updated_at,
        id: link.id,
        data: {}
      }

      if !link.author_name.blank? || !link.author_url.blank? || !link.author_photo.blank?
        obj[:data][:author] = {}
        obj[:data][:author][:name] = link.author_name if link.author_name
        if !link.author_url.blank?
          obj[:data][:author][:url] = Microformats2::AbsoluteUri.new(link.href, link.author_url).absolutize
        else
          obj[:data][:author][:url] = nil
        end
        if !link.author_photo.blank?
          obj[:data][:author][:photo] = Microformats2::AbsoluteUri.new(link.href, link.author_photo).absolutize
        else
          obj[:data][:author][:photo] = nil
        end
      end

      obj[:data][:url] = link.absolute_url
      obj[:data][:name] = link.name
      obj[:data][:content] = link.content
      obj[:data][:published] = link.published
      obj[:data][:published_ts] = link.published_ts

      obj[:activity] = {
        :type => link.type,
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
    jf2 = {
      type: "entry",
      author: {
        name: link.author_name,
        photo: link.author_photo,
        url: link.author_url
      },
      url: link.absolute_url,
      published: link.published,
      name: link.name,
    }

    if !link.summary.blank?
      jf2[:summary] = {
        :"content-type" => "text/html",
        :value => link.summary
      }
    end

    if !link.content.blank?
      jf2[:content] = {
        :"content-type" => "text/html",
        :value => link.content
      }
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
    when "rsvp-no"
      relation = :"rsvp"
      jf2[:rsvp] = "no"
    when "rsvp-maybe"
      relation = :"rsvp"
      jf2[:rsvp] = "maybe"
    else
      relation = :"mention-of"
    end

    jf2[relation] = link.page.href
    jf2[:'wm-property'] = relation

    jf2
  end

end
