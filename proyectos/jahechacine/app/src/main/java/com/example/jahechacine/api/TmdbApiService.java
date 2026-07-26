package com.example.jahechacine.api;

import com.example.jahechacine.model.MediaItem;
import com.example.jahechacine.model.TmdbResponse;

import retrofit2.Call;
import retrofit2.http.GET;

public interface TmdbApiService {
    @GET("movie/popular")
    Call<TmdbResponse<MediaItem>> getPopularMovies();

    @GET("tv/popular")
    Call<TmdbResponse<MediaItem>> getPopularSeries();

    @GET("trending/all/day")
    Call<TmdbResponse<MediaItem>> getTrending();
}