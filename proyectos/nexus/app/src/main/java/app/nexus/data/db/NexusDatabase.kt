package app.nexus.data.db

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase

@Database(
    entities = [
        SourceEntity::class,
        ProgressEntity::class,
        FavoriteEntity::class,
        SearchHistoryEntity::class
    ],
    version = 1,
    exportSchema = false
)
abstract class NexusDatabase : RoomDatabase() {

    abstract fun sources(): SourceDao
    abstract fun progress(): ProgressDao
    abstract fun favorites(): FavoriteDao
    abstract fun searchHistory(): SearchHistoryDao

    companion object {
        @Volatile
        private var instance: NexusDatabase? = null

        fun get(context: Context): NexusDatabase = instance ?: synchronized(this) {
            instance ?: Room.databaseBuilder(
                context.applicationContext,
                NexusDatabase::class.java,
                "nexus.db"
            ).build().also { instance = it }
        }
    }
}
