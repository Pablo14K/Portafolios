package com.example.jahechacine.model;

import com.google.gson.annotations.SerializedName;
import java.io.Serializable;

public class MediaItem implements Serializable {
    @SerializedName("id")
    private int id;
    
    @SerializedName(value = "title", alternate = {"name"})
    private String title;
    
    @SerializedName("overview")
    private String overview;
    
    @SerializedName("poster_path")
    private String posterPath;
    
    @SerializedName("backdrop_path")
    private String backdropPath;

    @SerializedName("media_type")
    private String mediaType; // "movie" or "tv"

    public int getId() { return id; }
    public String getTitle() { return title; }
    public String getOverview() { return overview; }
    public String getPosterPath() { return "https://image.tmdb.org/t/p/w500" + posterPath; }
    public String getBackdropPath() { return "https://image.tmdb.org/t/p/w780" + backdropPath; }
    public String getMediaType() { return mediaType; }
    public void setMediaType(String type) { this.mediaType = type; }
}